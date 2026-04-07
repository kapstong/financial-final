import { readdirSync } from 'node:fs';
import { join, extname } from 'node:path';
import { spawnSync } from 'node:child_process';

const root = process.cwd();
const excludeDirs = new Set(['.git', 'node_modules', 'vendor', 'uploads', 'logs', 'cache', 'backups']);
const allowedExts = new Set(['.js', '.mjs', '.cjs']);
const files = [];

function walk(dir) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const fullPath = join(dir, entry.name);
    if (entry.isDirectory()) {
      if (!excludeDirs.has(entry.name)) {
        walk(fullPath);
      }
      continue;
    }

    if (!entry.isFile()) {
      continue;
    }

    if (allowedExts.has(extname(entry.name).toLowerCase())) {
      files.push(fullPath);
    }
  }
}

walk(root);
files.sort((a, b) => a.localeCompare(b));

const failures = [];
for (const file of files) {
  const result = spawnSync(process.execPath, ['--check', file], {
    encoding: 'utf8'
  });

  if (result.status !== 0) {
    failures.push({
      file,
      output: (result.stderr || result.stdout || '').trim()
    });
  }
}

if (failures.length > 0) {
  console.error(`JavaScript lint failed for ${failures.length} file(s).`);
  for (const failure of failures) {
    console.error(failure.file);
    console.error(failure.output);
  }
  process.exit(1);
}

console.log(`JavaScript lint passed for ${files.length} file(s).`);
