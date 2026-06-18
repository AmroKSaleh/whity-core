import { readdir, readFile, stat } from 'node:fs/promises';
import { join, posix, relative, sep } from 'node:path';
import { crc32 } from 'node:zlib';

/**
 * A minimal, dependency-free ZIP writer for the plugin-upload E2E fixture
 * (WC-221).
 *
 * The repo ships no zip library, so this builds a STORED (uncompressed) ZIP
 * in-memory from a directory tree. Stored entries are the simplest correct ZIP
 * form — the only computed field is the CRC-32, which Node's `zlib.crc32`
 * provides. The backend installer detects a ZIP purely by its `PK\x03\x04`
 * local-file-header magic and re-derives everything from the central directory,
 * so a stored archive is accepted exactly like a compressed one.
 *
 * The produced archive contains a SINGLE top-level directory (the plugin dir),
 * which is what {@link PluginInstaller::singleTopLevelDir} requires.
 */

interface ZipEntry {
  /** Forward-slashed path inside the archive (dirs end with `/`). */
  name: string;
  /** File contents; empty for directory entries. */
  data: Buffer;
}

const SIG_LOCAL = 0x04034b50;
const SIG_CENTRAL = 0x02014b50;
const SIG_EOCD = 0x06054b50;
// Version 2.0, no flags, method 0 (stored). Fixed DOS date/time is fine.
const VERSION = 20;
const DOS_TIME = 0;
const DOS_DATE = 0x21; // 1980-01-01 (any valid value works).

function crc32Of(data: Buffer): number {
  // zlib.crc32 returns an unsigned 32-bit number.
  return crc32(data) >>> 0;
}

function localHeader(entry: ZipEntry): Buffer {
  const name = Buffer.from(entry.name, 'utf8');
  const head = Buffer.alloc(30);
  head.writeUInt32LE(SIG_LOCAL, 0);
  head.writeUInt16LE(VERSION, 4);
  head.writeUInt16LE(0, 6); // flags
  head.writeUInt16LE(0, 8); // method: stored
  head.writeUInt16LE(DOS_TIME, 10);
  head.writeUInt16LE(DOS_DATE, 12);
  head.writeUInt32LE(crc32Of(entry.data), 14);
  head.writeUInt32LE(entry.data.length, 18); // compressed size
  head.writeUInt32LE(entry.data.length, 22); // uncompressed size
  head.writeUInt16LE(name.length, 26);
  head.writeUInt16LE(0, 28); // extra length
  return Buffer.concat([head, name]);
}

function centralHeader(entry: ZipEntry, offset: number): Buffer {
  const name = Buffer.from(entry.name, 'utf8');
  const head = Buffer.alloc(46);
  head.writeUInt32LE(SIG_CENTRAL, 0);
  head.writeUInt16LE(VERSION, 4); // version made by
  head.writeUInt16LE(VERSION, 6); // version needed
  head.writeUInt16LE(0, 8); // flags
  head.writeUInt16LE(0, 10); // method: stored
  head.writeUInt16LE(DOS_TIME, 12);
  head.writeUInt16LE(DOS_DATE, 14);
  head.writeUInt32LE(crc32Of(entry.data), 16);
  head.writeUInt32LE(entry.data.length, 20); // compressed size
  head.writeUInt32LE(entry.data.length, 24); // uncompressed size
  head.writeUInt16LE(name.length, 28);
  head.writeUInt16LE(0, 30); // extra length
  head.writeUInt16LE(0, 32); // comment length
  head.writeUInt16LE(0, 34); // disk number start
  head.writeUInt16LE(0, 36); // internal attrs
  head.writeUInt32LE(0, 38); // external attrs
  head.writeUInt32LE(offset, 42); // local header offset
  return Buffer.concat([head, name]);
}

function buildZip(entries: ZipEntry[]): Buffer {
  const localParts: Buffer[] = [];
  const centralParts: Buffer[] = [];
  let offset = 0;

  for (const entry of entries) {
    const local = localHeader(entry);
    centralParts.push(centralHeader(entry, offset));
    localParts.push(local, entry.data);
    offset += local.length + entry.data.length;
  }

  const centralStart = offset;
  const central = Buffer.concat(centralParts);

  const eocd = Buffer.alloc(22);
  eocd.writeUInt32LE(SIG_EOCD, 0);
  eocd.writeUInt16LE(0, 4); // disk number
  eocd.writeUInt16LE(0, 6); // central dir disk
  eocd.writeUInt16LE(entries.length, 8); // entries on disk
  eocd.writeUInt16LE(entries.length, 10); // total entries
  eocd.writeUInt32LE(central.length, 12);
  eocd.writeUInt32LE(centralStart, 16);
  eocd.writeUInt16LE(0, 20); // comment length

  return Buffer.concat([Buffer.concat(localParts), central, eocd]);
}

/** Recursively collect file paths under a directory. */
async function walk(dir: string): Promise<string[]> {
  const out: string[] = [];
  for (const name of await readdir(dir)) {
    const full = join(dir, name);
    const info = await stat(full);
    if (info.isDirectory()) {
      out.push(...(await walk(full)));
    } else {
      out.push(full);
    }
  }
  return out;
}

/**
 * Build a STORED zip of `rootDir`'s tree, with archive paths made relative to
 * `rootDir`'s PARENT — so a directory `WC221UploadFixture/` becomes the single
 * top-level entry the installer expects. Returns the raw archive bytes.
 */
export async function zipPluginDir(rootDir: string): Promise<Buffer> {
  const parent = join(rootDir, '..');
  const files = await walk(rootDir);
  files.sort();

  const entries: ZipEntry[] = [];
  for (const file of files) {
    const rel = relative(parent, file).split(sep).join(posix.sep);
    entries.push({ name: rel, data: await readFile(file) });
  }
  return buildZip(entries);
}
