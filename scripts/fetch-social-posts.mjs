import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const OUTPUT_PATH = path.join(__dirname, "..", "src", "data", "social-posts.json");

const GRAPH_API_VERSION = "v21.0";

const { IG_ACCESS_TOKEN, IG_USER_ID, FB_PAGE_ACCESS_TOKEN, FB_PAGE_ID } = process.env;

async function fetchInstagramPosts() {
	if (!IG_ACCESS_TOKEN || !IG_USER_ID) {
		console.warn(
			"[fetch-social-posts] Instagram ignoré : IG_ACCESS_TOKEN ou IG_USER_ID manquant.",
		);
		return null;
	}

	const url = new URL(`https://graph.facebook.com/${GRAPH_API_VERSION}/${IG_USER_ID}/media`);
	url.searchParams.set("fields", "id,caption,media_type,media_url,thumbnail_url,permalink,timestamp");
	url.searchParams.set("access_token", IG_ACCESS_TOKEN);
	url.searchParams.set("limit", "6");

	const response = await fetch(url);
	if (!response.ok) {
		throw new Error(`Instagram API a répondu ${response.status} : ${await response.text()}`);
	}

	const body = await response.json();
	return (body.data ?? []).map((item) => ({
		id: `instagram-${item.id}`,
		source: "instagram",
		text: item.caption ?? "",
		image: item.media_type === "VIDEO" ? (item.thumbnail_url ?? null) : (item.media_url ?? null),
		url: item.permalink,
		date: item.timestamp,
	}));
}

async function fetchFacebookPosts() {
	if (!FB_PAGE_ACCESS_TOKEN || !FB_PAGE_ID) {
		console.warn(
			"[fetch-social-posts] Facebook ignoré : FB_PAGE_ACCESS_TOKEN ou FB_PAGE_ID manquant.",
		);
		return null;
	}

	const url = new URL(`https://graph.facebook.com/${GRAPH_API_VERSION}/${FB_PAGE_ID}/posts`);
	url.searchParams.set("fields", "id,message,full_picture,permalink_url,created_time");
	url.searchParams.set("access_token", FB_PAGE_ACCESS_TOKEN);
	url.searchParams.set("limit", "6");

	const response = await fetch(url);
	if (!response.ok) {
		throw new Error(`Facebook API a répondu ${response.status} : ${await response.text()}`);
	}

	const body = await response.json();
	return (body.data ?? []).map((item) => ({
		id: `facebook-${item.id}`,
		source: "facebook",
		text: item.message ?? "",
		image: item.full_picture ?? null,
		url: item.permalink_url,
		date: item.created_time,
	}));
}

async function readExistingPosts() {
	try {
		const raw = await readFile(OUTPUT_PATH, "utf-8");
		const parsed = JSON.parse(raw);
		return Array.isArray(parsed.posts) ? parsed.posts : [];
	} catch {
		return [];
	}
}

async function main() {
	const existingPosts = await readExistingPosts();
	const existingInstagram = existingPosts.filter((post) => post.source === "instagram");
	const existingFacebook = existingPosts.filter((post) => post.source === "facebook");

	let instagramPosts = existingInstagram;
	try {
		const fetched = await fetchInstagramPosts();
		if (fetched) instagramPosts = fetched;
	} catch (error) {
		console.warn(`[fetch-social-posts] Échec de la récupération Instagram : ${error.message}`);
	}

	let facebookPosts = existingFacebook;
	try {
		const fetched = await fetchFacebookPosts();
		if (fetched) facebookPosts = fetched;
	} catch (error) {
		console.warn(`[fetch-social-posts] Échec de la récupération Facebook : ${error.message}`);
	}

	const posts = [...instagramPosts, ...facebookPosts].sort(
		(a, b) => new Date(b.date).valueOf() - new Date(a.date).valueOf(),
	);

	const result = { updatedAt: new Date().toISOString(), posts };

	await mkdir(path.dirname(OUTPUT_PATH), { recursive: true });
	await writeFile(OUTPUT_PATH, `${JSON.stringify(result, null, 2)}\n`, "utf-8");

	console.log(
		`[fetch-social-posts] ${posts.length} post(s) écrit(s) dans ${path.relative(process.cwd(), OUTPUT_PATH)}`,
	);
}

main().catch((error) => {
	console.warn(`[fetch-social-posts] Erreur inattendue, le build continue : ${error.message}`);
});
