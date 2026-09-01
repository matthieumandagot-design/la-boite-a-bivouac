import { mkdir, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUTPUT_DIR = path.join(__dirname, "..", "dist", "api");
const OUTPUT_PATH = path.join(OUTPUT_DIR, "config.php");

const { ADMIN_USERNAME, ADMIN_PASSWORD_HASH, SMTP_PASSWORD, NEWSLETTER_TOKEN_SALT } = process.env;

function escapeSingleQuoted(value) {
	return value.replace(/\\/g, "\\\\").replace(/'/g, "\\'");
}

async function main() {
	if (!ADMIN_USERNAME || !ADMIN_PASSWORD_HASH || !SMTP_PASSWORD || !NEWSLETTER_TOKEN_SALT) {
		console.warn(
			"[write-api-config] Secret(s) manquant(s) (ADMIN_USERNAME, ADMIN_PASSWORD_HASH, SMTP_PASSWORD, NEWSLETTER_TOKEN_SALT) : dist/api/config.php non généré, /api/* répondra 503.",
		);
		return;
	}

	const content = `<?php
declare(strict_types=1);

define('SMTP_HOST', 'ssl0.ovh.net');
define('SMTP_PORT', 465);
define('SMTP_USER', 'contact@laboiteabivouac.fr');
define('SMTP_PASS', '${escapeSingleQuoted(SMTP_PASSWORD)}');
define('SMTP_FROM', 'contact@laboiteabivouac.fr');
define('SMTP_NAME', 'La Boîte à Bivouac');

define('ADMIN_USERNAME', '${escapeSingleQuoted(ADMIN_USERNAME)}');
define('ADMIN_PASSWORD_HASH', '${escapeSingleQuoted(ADMIN_PASSWORD_HASH)}');

define('UNSUBSCRIBE_SALT', '${escapeSingleQuoted(NEWSLETTER_TOKEN_SALT)}');
`;

	await mkdir(OUTPUT_DIR, { recursive: true });
	await writeFile(OUTPUT_PATH, content, "utf-8");

	console.log(`[write-api-config] ${path.relative(process.cwd(), OUTPUT_PATH)} écrit.`);
}

main();
