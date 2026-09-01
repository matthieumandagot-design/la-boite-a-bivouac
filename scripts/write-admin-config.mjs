import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUTPUT_DIR = path.join(__dirname, "..", "dist", "admin");
const OUTPUT_PATH = path.join(OUTPUT_DIR, "config.php");

const { ADMIN_USERNAME, ADMIN_PASSWORD_HASH } = process.env;

function escapeSingleQuoted(value) {
	return value.replace(/\\/g, "\\\\").replace(/'/g, "\\'");
}

async function main() {
	if (!ADMIN_USERNAME || !ADMIN_PASSWORD_HASH) {
		console.warn(
			"[write-admin-config] ADMIN_USERNAME ou ADMIN_PASSWORD_HASH manquant : dist/admin/config.php non généré, /admin/faq.php répondra 503.",
		);
		return;
	}

	const content = `<?php
declare(strict_types=1);

define('ADMIN_USERNAME', '${escapeSingleQuoted(ADMIN_USERNAME)}');
define('ADMIN_PASSWORD_HASH', '${escapeSingleQuoted(ADMIN_PASSWORD_HASH)}');
`;

	await mkdir(OUTPUT_DIR, { recursive: true });
	await writeFile(OUTPUT_PATH, content, "utf-8");

	console.log(`[write-admin-config] ${path.relative(process.cwd(), OUTPUT_PATH)} écrit.`);
}

main();
