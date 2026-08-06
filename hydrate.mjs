// hydrate.mjs — writes/refreshes the .tasker/ mirror in the CURRENT directory.
// Run: node hydrate.mjs   (then delete this file or keep it; the URL inside expires in ~15 min)
import { writeFileSync, mkdirSync, rmSync, existsSync } from 'node:fs'
import { join, dirname } from 'node:path'
const res = await fetch("https://rzjhmipbamyvpwlkfvxx.supabase.co/functions/v1/mcp?local_bundle=eyJwIjoiMmQyMTUxOWEtYTMzOS00NjkxLThmNzYtMjNjMmI3ZmE3OWU5IiwidSI6IjM3ZDMxYWU5LTllNzAtNGJjNS1hMDY5LThmYjQyMGI0NDQ0MiIsImQiOiJjbGF1ZGUtY29kZS1wbSIsImMiOjAsImV4cCI6MTc4NTkwMzE0NTQ2MCwibiI6ImRmODdhMzU5In0.Je9ugmMKJVS5dcI4AOiJDcP99WmNxgkIAKZnSbNrcaI")
if (!res.ok) { console.error('bundle fetch failed:', res.status, await res.text()); process.exit(1) }
const p = await res.json()
for (const [path, content] of Object.entries(p.files)) {
  const f = join('.tasker', path); mkdirSync(dirname(f), { recursive: true }); writeFileSync(f, content, 'utf8')
}
for (const sid of p.tombstoned_short_ids || []) {
  const f = join('.tasker', 'tasks', `${p.prefix}-${sid}.md`); if (existsSync(f)) rmSync(f)
}
console.log(JSON.stringify({ status: 'hydrated', file_count: Object.keys(p.files).length, cursor: p.cursor, lease: p.lease, next_free_ids: p.next_free_ids }, null, 2))
