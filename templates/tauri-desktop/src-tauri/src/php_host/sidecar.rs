//! Spawns and supervises the FrankenPHP child process serving the offline
//! PHP plugin host (the vendored `php-host/` app — DemoCatalog + PrintDemo
//! running unmodified against local SQLite).
//!
//! Uses `Shell::command()` with an absolute, resource-resolved path to
//! `frankenphp.exe` rather than `Shell::sidecar()` + `bundle.externalBin`.
//! Deliberately: FrankenPHP's Windows release is not a single self-contained
//! binary — `frankenphp.exe` ships alongside ~50 sibling DLLs it dynamically
//! links against, and Windows resolves those from the directory containing
//! the loading executable FIRST. `externalBin`'s one-file-per-sidecar model
//! would separate `frankenphp.exe` from its DLLs and break that resolution;
//! bundling the whole release tree as one `bundle.resources` folder (confirmed
//! working: frankenphp.exe + all its DLLs + `ext/` + `php.ini`, all
//! co-located) and spawning by absolute path keeps the directory intact.
//!
//! Crash recovery: a plain-thread supervisor restarts the child with
//! 1s/3s/8s backoff (up to 3 attempts) — mirrors `sync::scheduler`'s
//! plain-thread idiom rather than adding more async. Each attempt re-picks a
//! free port (self-heals the port-collision TOCTOU noted in `pick_free_port`)
//! and re-assigns the new child to the same defense-in-depth Windows Job
//! Object story (see `job_object.rs`).
//!
//! Restart-on-demand (WC-desktop-plugins): a newly installed plugin (written
//! into `plugins_root()`, the writable `plugins-downloaded/` dir this module
//! creates) isn't picked up until the FrankenPHP worker restarts — it loads
//! plugins once at process boot (`php-host/public/index.php`). `restart()`
//! reuses the crash-supervisor's respawn machinery but skips its backoff/
//! attempt-budget/`Crashed` event, since this is a deliberate reload, not a
//! failure.

use crate::php_host::native_bridge::NativeBridgeHandle;
use std::net::TcpListener;
use std::path::{Path, PathBuf};
use std::sync::atomic::{AtomicBool, AtomicU16, Ordering};
use std::sync::{mpsc, Arc, Mutex};
use std::thread;
use std::time::Duration;
use tauri::path::BaseDirectory;
use tauri::{AppHandle, Emitter, Manager};
use tauri_plugin_shell::process::{CommandChild, CommandEvent};
use tauri_plugin_shell::ShellExt;

/// Strip Windows' `\\?\` verbatim-path prefix. `PathResolver::resolve()`
/// returns canonicalized (verbatim-prefixed) paths on Windows — the OS's own
/// APIs (and Rust's `Path::exists()`) handle that prefix fine, but PHP's own
/// file-open layer does not: a `--worker`/`--root` path passed verbatim
/// produced a "Failed opening required" fatal error for a file that
/// objectively existed (confirmed: `.exists()` returned true on the exact
/// same verbatim path PHP failed to open). No-op on non-Windows paths.
fn simplify_path(path: &Path) -> PathBuf {
    match path.to_str().and_then(|s| s.strip_prefix(r"\\?\")) {
        Some(stripped) => PathBuf::from(stripped),
        None => path.to_path_buf(),
    }
}

#[derive(serde::Serialize, Clone)]
#[serde(rename_all = "camelCase", tag = "state")]
pub enum PhpStatusEvent {
    Starting,
    Ready { port: u16 },
    Crashed { message: String },
    Restarting { attempt: u32 },
    /// A DELIBERATE reload (e.g. to pick up a newly installed plugin) — as
    /// opposed to `Restarting`, which is crash recovery. Distinguished so the
    /// frontend can show "installing…" rather than an alarming crash message.
    Reloading,
    Failed { message: String },
}

/// Backoff before each restart attempt; length also caps the attempt count.
const BACKOFF_SECONDS: [u64; 3] = [1, 3, 8];

enum Signal {
    Terminated(String),
}

/// Everything a spawn attempt needs, bundled so both the initial `spawn()` and
/// every later respawn (crash-recovery or deliberate `restart()`) go through
/// one code path with identical arguments.
#[derive(Clone)]
struct SpawnContext {
    frankenphp_exe: PathBuf,
    frankenphp_dir: PathBuf,
    public_dir: PathBuf,
    index_php: PathBuf,
    sqlite_path: PathBuf,
    /// Writable root a device downloads new plugins into at runtime — passed to
    /// PHP as `WHITY_DOWNLOADED_PLUGINS_ROOT` (see `php-host/public/index.php`
    /// and `PluginRuntimeLoader`'s multi-root discovery).
    downloaded_plugins_root: PathBuf,
    bridge_url: String,
    bridge_secret: String,
}

pub struct PhpSidecarHandle {
    child: Arc<Mutex<Option<CommandChild>>>,
    ready: Arc<AtomicBool>,
    shutting_down: Arc<AtomicBool>,
    /// Set (before killing) by `restart()` so the supervisor thread's next
    /// `Terminated` signal is treated as a deliberate reload, not a crash —
    /// skipping backoff/attempt-budget/the `Crashed` event.
    restarting: Arc<AtomicBool>,
    port: Arc<AtomicU16>,
    downloaded_plugins_root: PathBuf,
    app: AppHandle,
    ctx: SpawnContext,
    signal_tx: mpsc::Sender<Signal>,
}

impl PhpSidecarHandle {
    pub fn is_ready(&self) -> bool {
        self.ready.load(Ordering::SeqCst)
    }

    /// The FrankenPHP listen port, current as of the latest (re)start.
    pub fn port(&self) -> u16 {
        self.port.load(Ordering::SeqCst)
    }

    /// The writable root a device downloads new plugins into (distinct from
    /// the read-only bundled `php-host/plugins` resource tree).
    pub fn plugins_root(&self) -> &Path {
        &self.downloaded_plugins_root
    }

    /// Kill the FrankenPHP child and stop the crash-restart supervisor.
    /// Safe to call more than once (a no-op after the first successful
    /// kill, since `child` is taken out of the Mutex).
    pub fn shutdown(&self) {
        // Set BEFORE killing: the child's death is observed asynchronously
        // by the supervisor thread, which must see "intentional" already
        // true by the time it processes the resulting Terminated signal, or
        // it would try to restart a sidecar we're deliberately stopping.
        self.shutting_down.store(true, Ordering::SeqCst);
        if let Some(child) = self.child.lock().unwrap().take() {
            let _ = child.kill();
        }
    }

    /// Deliberately reload FrankenPHP (e.g. a plugin install just landed in
    /// `plugins_root()` and needs the worker to re-run its once-at-boot
    /// discovery). Kills the current child; the crash-supervisor thread does
    /// the actual respawn, immediately and without backoff, once it sees
    /// `restarting` set.
    ///
    /// Race note: if a crash-backoff is ALREADY in flight (the child died on
    /// its own moments earlier and the supervisor is mid-sleep before its own
    /// respawn), `child` still holds that already-dead handle rather than
    /// `None` — this method's `kill()` on it is a harmless no-op, and the
    /// in-flight crash-recovery's respawn proceeds as its own iteration
    /// completes, at which point `restarting` is consumed by coincidence on
    /// whatever `Terminated` signal arrives next. Vanishingly rare on a
    /// single-user desktop app, and self-correcting (at worst one future
    /// crash skips its backoff) — same TOCTOU-accepted spirit as
    /// `pick_free_port` below.
    pub fn restart(&self) {
        self.restarting.store(true, Ordering::SeqCst);
        self.ready.store(false, Ordering::SeqCst);
        let _ = self.app.emit("php:status", PhpStatusEvent::Reloading);

        match self.child.lock().unwrap().take() {
            Some(child) => {
                let _ = child.kill(); // supervisor's Terminated handler respawns
            }
            None => {
                // No live child to kill (e.g. called right after shutdown(),
                // or the raciest edge of the note above) — nothing will
                // signal the supervisor, so respawn inline.
                self.restarting.store(false, Ordering::SeqCst);
                let _ = do_spawn(&self.app, &self.ctx, &self.child, &self.ready, &self.port, self.signal_tx.clone());
            }
        }
    }
}

fn spawn_attempt(
    app: &AppHandle,
    ctx: &SpawnContext,
    port: u16,
    signal_tx: mpsc::Sender<Signal>,
) -> Result<CommandChild, String> {
    let command = app
        .shell()
        .command(&ctx.frankenphp_exe)
        .current_dir(&ctx.frankenphp_dir)
        .args([
            "php-server",
            "--root",
            &ctx.public_dir.to_string_lossy(),
            "--listen",
            &format!("127.0.0.1:{port}"),
            "--worker",
            // Pinned to exactly 1 worker (`,1` suffix), not FrankenPHP's
            // default of one-per-CPU-core. Confirmed live (via the Linux
            // static-build spike) that >1 worker races at boot: every worker
            // independently runs MigrationRunner on first start, and
            // concurrent INSERTs into _plugin_migrations threw real
            // "UNIQUE constraint failed" crashes under ~33 parallel workers.
            // A single desktop user has no need for more than one anyway.
            &format!("{},1", ctx.index_php.to_string_lossy()),
        ])
        .env("WHITY_SQLITE_PATH", ctx.sqlite_path.to_string_lossy().to_string())
        .env("WHITY_OFFLINE_TENANT_ID", "1")
        .env("WHITY_NATIVE_BRIDGE_URL", &ctx.bridge_url)
        .env("WHITY_NATIVE_BRIDGE_SECRET", &ctx.bridge_secret)
        .env(
            "WHITY_DOWNLOADED_PLUGINS_ROOT",
            ctx.downloaded_plugins_root.to_string_lossy().to_string(),
        );

    let (mut rx, child) = command.spawn().map_err(|e| format!("failed to spawn frankenphp: {e}"))?;

    #[cfg(target_os = "windows")]
    crate::php_host::job_object::assign(child.pid());

    // The only async code in this whole feature: tauri-plugin-shell's event
    // channel is async-only. Forward straight into logging/the signal
    // channel, matching this crate's otherwise fully blocking/plain-thread
    // style everywhere else (reqwest::blocking, sync::scheduler's OS-thread
    // loop). `signaled` dedupes Error+Terminated both firing for one crash.
    let signaled = Arc::new(AtomicBool::new(false));
    tauri::async_runtime::spawn(async move {
        while let Some(event) = rx.recv().await {
            match event {
                CommandEvent::Stderr(bytes) => {
                    eprintln!("[frankenphp] {}", String::from_utf8_lossy(&bytes));
                }
                CommandEvent::Stdout(bytes) => {
                    eprintln!("[frankenphp] {}", String::from_utf8_lossy(&bytes));
                }
                CommandEvent::Error(message) => {
                    eprintln!("[frankenphp] error: {message}");
                    if !signaled.swap(true, Ordering::SeqCst) {
                        let _ = signal_tx.send(Signal::Terminated(message));
                    }
                }
                CommandEvent::Terminated(payload) => {
                    let message = format!("process terminated: {payload:?}");
                    eprintln!("[frankenphp] {message}");
                    if !signaled.swap(true, Ordering::SeqCst) {
                        let _ = signal_tx.send(Signal::Terminated(message));
                    }
                }
                _ => {}
            }
        }
    });

    Ok(child)
}

fn spawn_readiness_poll(app: AppHandle, ready: Arc<AtomicBool>, port: u16) {
    thread::spawn(move || poll_readiness(app, ready, port));
}

/// Pick a fresh port, spawn FrankenPHP, install the new child, and start
/// polling its readiness — the one respawn sequence shared by the initial
/// `spawn()`, crash-recovery, and a deliberate `restart()`.
fn do_spawn(
    app: &AppHandle,
    ctx: &SpawnContext,
    child: &Arc<Mutex<Option<CommandChild>>>,
    ready: &Arc<AtomicBool>,
    port: &Arc<AtomicU16>,
    signal_tx: mpsc::Sender<Signal>,
) -> Result<(), String> {
    let new_port = pick_free_port()?;
    port.store(new_port, Ordering::SeqCst);
    let new_child = spawn_attempt(app, ctx, new_port, signal_tx)?;
    *child.lock().unwrap() = Some(new_child);
    spawn_readiness_poll(app.clone(), ready.clone(), new_port);
    Ok(())
}

pub fn spawn(app: AppHandle, bridge: &NativeBridgeHandle) -> Result<PhpSidecarHandle, String> {
    let frankenphp_dir = simplify_path(
        &app.path()
            .resolve("frankenphp", BaseDirectory::Resource)
            .map_err(|e| format!("failed to resolve frankenphp resource dir: {e}"))?,
    );
    let php_host_dir = simplify_path(
        &app.path()
            .resolve("php-host", BaseDirectory::Resource)
            .map_err(|e| format!("failed to resolve php-host resource dir: {e}"))?,
    );

    let frankenphp_exe = frankenphp_dir.join("frankenphp.exe");
    let public_dir = php_host_dir.join("public");
    let index_php = public_dir.join("index.php");

    let app_data_dir = simplify_path(
        &app.path()
            .app_data_dir()
            .map_err(|e| format!("failed to resolve app data dir: {e}"))?,
    );
    let sqlite_path = app_data_dir.join("whity-offline.sqlite");
    if let Some(parent) = sqlite_path.parent() {
        std::fs::create_dir_all(parent).map_err(|e| format!("failed to create app data dir: {e}"))?;
    }

    // Writable root for runtime-downloaded plugins (WC-desktop-plugins) —
    // distinct from the read-only bundled `php-host/plugins` resource tree.
    let downloaded_plugins_root = app_data_dir.join("plugins-downloaded");
    std::fs::create_dir_all(&downloaded_plugins_root)
        .map_err(|e| format!("failed to create the downloaded-plugins dir: {e}"))?;

    let bridge_url = format!("http://127.0.0.1:{}", bridge.port);
    let bridge_secret = bridge.secret.clone();

    let ctx = SpawnContext {
        frankenphp_exe,
        frankenphp_dir,
        public_dir,
        index_php,
        sqlite_path,
        downloaded_plugins_root: downloaded_plugins_root.clone(),
        bridge_url,
        bridge_secret,
    };

    let ready = Arc::new(AtomicBool::new(false));
    let shutting_down = Arc::new(AtomicBool::new(false));
    let restarting = Arc::new(AtomicBool::new(false));
    let port = Arc::new(AtomicU16::new(0));
    let child: Arc<Mutex<Option<CommandChild>>> = Arc::new(Mutex::new(None));
    let (signal_tx, signal_rx) = mpsc::channel::<Signal>();

    let _ = app.emit("php:status", PhpStatusEvent::Starting);

    do_spawn(&app, &ctx, &child, &ready, &port, signal_tx.clone())?;

    // Supervisor: waits for a termination signal, restarts with backoff
    // unless we're intentionally shutting down or have exhausted attempts —
    // or, for a deliberate restart() call, respawns immediately (no backoff,
    // no Crashed/Restarting events, and the attempt budget resets).
    {
        let app = app.clone();
        let child = child.clone();
        let ready = ready.clone();
        let shutting_down = shutting_down.clone();
        let restarting = restarting.clone();
        let port = port.clone();
        let ctx = ctx.clone();
        let signal_tx_for_respawns = signal_tx.clone();
        thread::Builder::new()
            .name("whity-php-supervisor".into())
            .spawn(move || {
                let mut attempt = 0usize;
                while let Ok(Signal::Terminated(message)) = signal_rx.recv() {
                    ready.store(false, Ordering::SeqCst);
                    if shutting_down.load(Ordering::SeqCst) {
                        break;
                    }

                    if restarting.swap(false, Ordering::SeqCst) {
                        attempt = 0; // a successful deliberate restart earns a fresh crash budget
                        if let Err(e) =
                            do_spawn(&app, &ctx, &child, &ready, &port, signal_tx_for_respawns.clone())
                        {
                            let _ = app.emit("php:status", PhpStatusEvent::Failed { message: e });
                            break;
                        }
                        continue;
                    }

                    let _ = app.emit("php:status", PhpStatusEvent::Crashed { message: message.clone() });

                    if attempt >= BACKOFF_SECONDS.len() {
                        let _ = app.emit(
                            "php:status",
                            PhpStatusEvent::Failed {
                                message: format!(
                                    "gave up after {} restart attempts: {message}",
                                    BACKOFF_SECONDS.len()
                                ),
                            },
                        );
                        break;
                    }

                    let _ = app.emit("php:status", PhpStatusEvent::Restarting { attempt: attempt as u32 + 1 });
                    thread::sleep(Duration::from_secs(BACKOFF_SECONDS[attempt]));
                    attempt += 1;

                    if shutting_down.load(Ordering::SeqCst) {
                        break;
                    }

                    if let Err(e) = do_spawn(&app, &ctx, &child, &ready, &port, signal_tx_for_respawns.clone()) {
                        let _ = app.emit("php:status", PhpStatusEvent::Failed { message: e });
                        break;
                    }
                }
            })
            .map_err(|e| format!("failed to start php supervisor thread: {e}"))?;
    }

    Ok(PhpSidecarHandle {
        child,
        ready,
        shutting_down,
        restarting,
        port,
        downloaded_plugins_root,
        app,
        ctx,
        signal_tx,
    })
}

fn poll_readiness(app: AppHandle, ready: Arc<AtomicBool>, port: u16) {
    let client = reqwest::blocking::Client::builder()
        .timeout(Duration::from_secs(2))
        .build()
        .expect("client builder with static config never fails");
    let url = format!("http://127.0.0.1:{port}/__whity/health");
    let deadline = std::time::Instant::now() + Duration::from_secs(30);

    loop {
        if let Ok(response) = client.get(&url).send() {
            if response.status().is_success() {
                ready.store(true, Ordering::SeqCst);
                let _ = app.emit("php:status", PhpStatusEvent::Ready { port });
                return;
            }
        }

        if std::time::Instant::now() >= deadline {
            let _ = app.emit(
                "php:status",
                PhpStatusEvent::Failed { message: "php-host did not become ready within 30s".into() },
            );
            return;
        }

        thread::sleep(Duration::from_millis(300));
    }
}

/// Bind an OS-assigned loopback port, read it, then drop the listener before
/// handing the port number to FrankenPHP. TOCTOU-accepted (another process
/// could theoretically grab the port first) — self-heals via the
/// crash-restart supervisor above, which re-picks a fresh port every attempt.
fn pick_free_port() -> Result<u16, String> {
    let listener = TcpListener::bind("127.0.0.1:0").map_err(|e| format!("failed to pick a free port: {e}"))?;
    listener
        .local_addr()
        .map(|addr| addr.port())
        .map_err(|e| format!("failed to read bound port: {e}"))
}
