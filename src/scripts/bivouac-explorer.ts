/** Scroll-driven illustration. The atlas is an original render made from the
 * site's product photos. Paths below are display masks, not substitute artwork.
 * No wheel interception: normal scroll, touch, keyboard and reverse all work. */
const clamp = (value: number) => Math.max(0, Math.min(1, value));
const smooth = (value: number) => { const t = clamp(value); return t * t * (3 - 2 * t); };
const lerp = (from: number, to: number, t: number) => from + (to - from) * t;
type Piece = { image: HTMLCanvasElement; width: number; height: number };
type Crop = { x: number; y: number; w: number; h: number; outline: string; holes?: string[] };

const CROPS: Record<string, Crop> = {
  closed: { x: 250, y: 65, w: 760, h: 215,
    outline: 'M264 199 L263 124 L266 109 L280 104 L390 88 L691 68 L835 72 L978 81 L993 87 L995 107 L995 191 L920 203 L916 219 L902 217 L904 205 L725 239 L729 261 L712 264 L708 241 L566 251 L473 239 L469 244 L455 243 L455 237 L342 220 L337 226 L324 223 L328 217 Z' },
  box: { x: 83, y: 270, w: 976, h: 535,
    outline: 'M85 577 L132 562 L229 410 L225 378 L225 364 L304 351 L304 326 L320 323 L411 337 L634 304 L634 282 L650 280 L975 315 L1055 324 L1056 342 L1047 351 L1045 562 L1040 652 L572 800 L239 707 L238 630 L85 592 Z',
    holes: ['M151 552 L227 438 L230 540 Z'] },
  trailer: { x: 50, y: 770, w: 1040, h: 540,
    outline: 'M57 1221 L86 1197 L90 1173 L131 1137 L149 1133 L157 1142 L154 1154 L126 1175 L172 1153 L235 1145 L235 1070 L245 1049 L242 1037 L263 1035 L268 1049 L265 1070 L270 1099 L308 1070 L267 1040 L262 904 L264 882 L757 773 L772 775 L1047 819 L1065 821 L1067 834 L1075 837 L1076 858 L1067 867 L1063 1004 L1053 1016 L1037 1010 L1024 1024 L1027 1055 L1012 1064 Q1020 1158 981 1204 Q933 1249 887 1219 Q850 1200 841 1153 L816 1106 L659 1151 L649 1174 L625 1182 L610 1171 L588 1181 L574 1176 L574 1159 L543 1151 L362 1186 L319 1200 Q361 1234 342 1276 Q325 1313 289 1303 Q252 1297 255 1260 L239 1260 L222 1247 L224 1209 L194 1218 Q193 1250 164 1251 L142 1242 L113 1240 L69 1239 L57 1232 Z',
    holes: ['M272 1107 L274 1144 L333 1128 L378 1080 Z', 'M280 1144 L350 1110 L511 1143 Z'] },
};

function cutPiece(atlas: HTMLImageElement, crop: Crop): Piece {
  const image = document.createElement('canvas'); image.width = crop.w; image.height = crop.h;
  const context = image.getContext('2d')!;
  context.translate(-crop.x, -crop.y);
  const shape = new Path2D(crop.outline);
  crop.holes?.forEach((path) => shape.addPath(new Path2D(path)));
  context.clip(shape, 'evenodd');
  context.drawImage(atlas, 0, 0);
  return { image, width: crop.w, height: crop.h };
}

function mount(root: HTMLElement) {
  if (root.dataset.mounted) return;
  root.dataset.mounted = 'true';
  const canvas = root.querySelector<HTMLCanvasElement>('canvas')!;
  const context = canvas.getContext('2d');
  if (!context) return;
  const fallback = root.querySelector<HTMLImageElement>('.explorer-fallback')!;
  const pin = root.querySelector<HTMLElement>('.explorer-pin')!;
  const stories = [...root.querySelectorAll<HTMLElement>('[data-story]')];
  const buttons = [...root.querySelectorAll<HTMLButtonElement>('[data-step]')];
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const shortScreen = window.matchMedia('(max-height: 650px)');
  const lifetime = new AbortController();
  let pieces: Record<string, Piece> | undefined;
  let frame = 0, progress = 0, previousStage = -1, visible = false;
  let top = 0, travel = 1, offset = 81;
  const manual = () => reducedMotion.matches || shortScreen.matches;

  function drawPiece(name: string, x: number, y: number, width: number, height?: number, alpha = 1) {
    const piece = pieces![name];
    context!.globalAlpha = clamp(alpha);
    context!.drawImage(piece.image, x, y, width, height ?? width * piece.height / piece.width);
    context!.globalAlpha = 1;
  }

  function render(value: number) {
    if (!pieces) return;
    progress = clamp(value);
    const split = smooth((progress - .16) / .70);
    context!.clearRect(0, 0, 1100, 960);
    // Pull back as the assembly opens up, keeping all three pieces in frame.
    context!.save(); context!.translate(550, 480);
    context!.scale(lerp(1, .60, split), lerp(1, .60, split));
    context!.translate(-550, -480);
    // Small studio grounding shadow; the product itself always uses the atlas.
    context!.save(); context!.translate(550, 905 + split * 300); context!.scale(1, .16);
    const shadow = context!.createRadialGradient(0, 0, 20, 0, 0, 310);
    shadow.addColorStop(0, '#060f0990'); shadow.addColorStop(1, '#060f0900');
    context!.fillStyle = shadow; context!.beginPath(); context!.arc(0, 0, 310, 0, Math.PI * 2); context!.fill(); context!.restore();
    // Matched-scale photos: the lower third of the made-to-measure box is
    // INSIDE the bed. The short front face has the tabletop, not rear doors.
    const trailerX = CROPS.trailer.x, trailerY = 380 + split * 340, trailerW = CROPS.trailer.w;
    drawPiece('trailer', trailerX, trailerY, trailerW);
    const insertScale = .93;
    drawPiece('box', 71 + CROPS.box.x * insertScale,
      -41 + CROPS.box.y * insertScale, CROPS.box.w * insertScale);
    // Repaint only the near ridelles in front of the inserted lower skirt.
    // Their clipping boundary moves with the trailer: no fading/early reveal.
    const railOffset = -390 + split * 340;
    context!.save(); context!.beginPath();
    context!.moveTo(266, 888 + railOffset); context!.lineTo(595, 970 + railOffset);
    context!.lineTo(1068, 828 + railOffset); context!.lineTo(1100, 1320 + railOffset);
    context!.lineTo(0, 1320 + railOffset); context!.closePath(); context!.clip();
    drawPiece('trailer', trailerX, trailerY, trailerW);
    context!.restore();
    // The tabletop projects OUTSIDE the bed, so it must remain in front of
    // the front ridelle even though the lower part of the box is behind it.
    context!.save(); context!.translate(71, -41); context!.scale(insertScale, insertScale);
    context!.clip(new Path2D('M85 578 L237 544 L557 619 L557 640 L425 681 L85 592 Z'));
    context!.drawImage(pieces.box.image, CROPS.box.x, CROPS.box.y);
    context!.restore();
    // Only the BOX carries transverse roof bars. The tent bag has no rails
    // underneath and rests directly on those two box-mounted crossbars.
    drawPiece('closed', 71 + CROPS.closed.x * insertScale,
      86 + CROPS.closed.y * insertScale - split * 170, CROPS.closed.w * insertScale);
    context!.restore();
    root.style.setProperty('--label-opacity', String(smooth((split - .58) / .36)));
    root.style.setProperty('--explorer-progress', String(progress));
    root.dataset.phase = split > .02 ? 'separated' : 'assembled';
    const stage = split > .12 ? 1 : 0;
    if (stage !== previousStage) {
      stories.forEach((story, i) => { story.hidden = i !== stage; });
      buttons.forEach((button, i) => button.setAttribute('aria-pressed', String(i === stage)));
      previousStage = stage;
    }
  }

  function schedule() {
    if (frame || !pieces || !visible || manual()) return;
    let previousTime = performance.now();
    const tick = (now: number) => {
      frame = 0;
      if (!visible || manual()) return;
      const target = clamp((window.scrollY - top + offset) / travel);
      const elapsed = Math.min(64, Math.max(1, now - previousTime));
      previousTime = now;
      // A short, frame-rate-independent damping removes wheel notches without
      // intercepting scrolling. Stop drawing as soon as the target is reached.
      const next = lerp(progress, target, 1 - Math.exp(-elapsed / 85));
      if (Math.abs(target - next) < .0001) { render(target); return; }
      render(next);
      frame = requestAnimationFrame(tick);
    };
    frame = requestAnimationFrame(tick);
  }
  function measure() {
    offset = document.querySelector('body > header')?.getBoundingClientRect().height ?? 81;
    root.style.setProperty('--header-offset', `${offset}px`);
    root.dataset.reduced = String(manual());
    top = root.getBoundingClientRect().top + window.scrollY;
    travel = Math.max(1, root.offsetHeight - pin.offsetHeight);
    if (pieces) { if (manual()) render(progress); else schedule(); }
  }
  buttons.forEach((button, index) => button.addEventListener('click', () => {
    const value = [0, 1][index];
    if (manual()) render(value);
    else { measure(); window.scrollTo({ top: top - offset + travel * value, behavior: 'smooth' }); }
  }, { signal: lifetime.signal }));
  window.addEventListener('scroll', schedule, { passive: true, signal: lifetime.signal });
  window.addEventListener('resize', measure, { passive: true, signal: lifetime.signal });
  reducedMotion.addEventListener('change', measure, { signal: lifetime.signal });
  shortScreen.addEventListener('change', measure, { signal: lifetime.signal });
  const resizeObserver = new ResizeObserver(measure);
  resizeObserver.observe(root);
  const header = document.querySelector('body > header'); if (header) resizeObserver.observe(header);
  document.fonts.ready.then(measure);

  let loading = false;
  const observer = new IntersectionObserver((entries) => {
    visible = entries.some((entry) => entry.isIntersecting);
    if (!visible) return;
    if (pieces) { measure(); schedule(); return; }
    if (loading) return;
    loading = true;
    const atlas = new Image(); atlas.decoding = 'async';
    atlas.onload = () => {
      if (lifetime.signal.aborted) return;
      const scrollBeforeLoad = window.scrollY;
      pieces = Object.fromEntries(Object.entries(CROPS).map(([name, crop]) => [name, cutPiece(atlas, crop)]));
      canvas.hidden = false; fallback.hidden = true;
      root.querySelector<HTMLElement>('.explorer-controls')!.hidden = false;
      root.dataset.ready = 'true';
      // Expanding the scroll track must not let scroll anchoring jump to its end.
      window.scrollTo({ top: scrollBeforeLoad, behavior: 'instant' });
      measure(); render(manual() ? 0 : (window.scrollY - top + offset) / travel);
    };
    atlas.onerror = () => { loading = false; root.dataset.assetError = 'true'; };
    atlas.src = '/images/bivouac-explorer/product-atlas-barres.webp';
  }, { rootMargin: '500px 0px' });
  observer.observe(root);
  document.addEventListener('astro:before-swap', () => {
    lifetime.abort(); observer.disconnect(); resizeObserver.disconnect(); cancelAnimationFrame(frame);
  }, { once: true });
}

export function mountBivouacExplorers() {
  document.querySelectorAll<HTMLElement>('[data-bivouac-explorer]').forEach(mount);
}
document.addEventListener('astro:page-load', mountBivouacExplorers);
