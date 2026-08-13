//! Windows Job Object assignment for the FrankenPHP child. Windows-only —
//! no Linux/macOS equivalent in v1 (see the plan doc's accepted residual
//! risk). Ensures the OS kills the child if this app dies ANY way,
//! including a hard kill (Task Manager "End task", `taskkill /F`) that
//! `RunEvent::ExitRequested` cannot observe — the standard pattern Chrome,
//! VS Code, and Docker Desktop use for helper processes.

use windows::Win32::Foundation::CloseHandle;
use windows::Win32::System::Threading::{OpenProcess, PROCESS_SET_QUOTA, PROCESS_TERMINATE};

/// Best-effort: logs and returns on any failure rather than propagating an
/// error. This is defense-in-depth, not load-bearing for the app to
/// function — a failure here just means the pre-existing (accepted) hard-
/// kill orphan risk remains for this particular launch.
pub fn assign(pid: u32) {
    let job = match win32job::Job::create() {
        Ok(job) => job,
        Err(e) => {
            eprintln!("[php_host] job object: failed to create: {e}");
            return;
        }
    };

    let mut info = match job.query_extended_limit_info() {
        Ok(info) => info,
        Err(e) => {
            eprintln!("[php_host] job object: failed to query limits: {e}");
            return;
        }
    };
    info.limit_kill_on_job_close();
    if let Err(e) = job.set_extended_limit_info(&mut info) {
        eprintln!("[php_host] job object: failed to set limits: {e}");
        return;
    }

    let handle = match unsafe { OpenProcess(PROCESS_SET_QUOTA | PROCESS_TERMINATE, false, pid) } {
        Ok(handle) => handle,
        Err(e) => {
            eprintln!("[php_host] job object: failed to open frankenphp process (pid {pid}): {e}");
            return;
        }
    };

    if let Err(e) = job.assign_process(handle.0 as isize) {
        eprintln!("[php_host] job object: failed to assign process: {e}");
    }
    unsafe {
        let _ = CloseHandle(handle);
    }

    // Deliberately leaked: KILL_ON_JOB_CLOSE only protects for as long as the
    // job handle stays open. It must live for the whole app lifetime, so the
    // OS kills frankenphp whenever THIS process ends, any way — dropping
    // `job` here would close the handle immediately and defeat the point.
    std::mem::forget(job);
}
