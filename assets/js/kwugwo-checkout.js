/*!
 * @kwugwo/checkout 0.1.3 - Kwugwo embedded checkout SDK (unminified IIFE build).
 *
 * Source:  https://github.com/Kwugwo/checkout-js (tag/commit "0.1.3")
 * Package: https://www.npmjs.com/package/@kwugwo/checkout
 * License: MIT, Copyright (c) 2026 Kwugwo
 *
 * Built from the source with `npm ci && BUILD_VARIANT=dev npx tsup`
 * (dist/kwugwo-checkout.global.js). The source map comment is removed.
 */
"use strict";
var KwugwoCheckout = (() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __export = (target, all) => {
    for (var name in all)
      __defProp(target, name, { get: all[name], enumerable: true });
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

  // src/global.ts
  var global_exports = {};
  __export(global_exports, {
    MESSAGE_SOURCE: () => MESSAGE_SOURCE2,
    init: () => init
  });

  // src/messages.ts
  var MESSAGE_SOURCE = "kwugwo";
  function isKwugwoMessage(data) {
    if (!data || typeof data !== "object") return false;
    const m = data;
    return m.source === MESSAGE_SOURCE && typeof m.type === "string";
  }

  // src/styles.ts
  var STYLE_ID = "kwugwo-checkout-styles";
  function injectStyles() {
    if (typeof document === "undefined") return;
    if (document.getElementById(STYLE_ID)) return;
    const style = document.createElement("style");
    style.id = STYLE_ID;
    style.textContent = `
@property --kwugwo-slab { syntax: '<color>'; inherits: true; initial-value: #BFE9E1; }

[data-kwugwo-root] {
  --kwugwo-surface: #FFFFFF;
  --kwugwo-wash: #D9FCF7;
  --kwugwo-bar: #BFE9E1;
  --kwugwo-sheen: rgba(255, 255, 255, 0.55);
  --kwugwo-hard: #001915;
  --kwugwo-backdrop: rgba(0, 25, 21, 0.5);
  --kwugwo-accent: #14B8A6;
  /* The accent-line colour of the page, reported by the hosted checkout.
     Falls back to Kwugwo teal for the current theme. */
  --kwugwo-slab: var(--kwugwo-slab-reported, #BFE9E1);
  --kwugwo-ease: cubic-bezier(0.2, 0.8, 0.2, 1);
  --kwugwo-bounce: cubic-bezier(0.34, 1.56, 0.64, 1);

  /* --kwugwo-content-height is set inline from the hosted page's resize
     message. The frame never shrinks below the default modal height and never
     grows past the viewport (less room for the close button above it), so a
     page taller than the screen still scrolls internally. The 96px reserve is
     split above and below by the centering, leaving room for the close button
     that sits 48px above the frame and the 14px slab below it. */
  --kwugwo-frame-height: min(max(720px, var(--kwugwo-content-height, 720px)), calc(100vh - 96px));
  /* Leaves room on the right for the offset slab. */
  --kwugwo-frame-width: min(980px, calc(100vw - 48px));
  position: fixed;
  inset: 0;
  z-index: 2147483647;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  transition: opacity 180ms ease, --kwugwo-slab 1.6s var(--kwugwo-ease);
  font-family: 'Mona Sans', system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
}
[data-kwugwo-root][data-open="true"] { opacity: 1; }

/* Before the hosted page reports its theme, follow the OS preference. */
@media (prefers-color-scheme: dark) {
  [data-kwugwo-root]:not([data-theme="light"]) {
    --kwugwo-surface: #0B2326;
    --kwugwo-wash: #0F3331;
    --kwugwo-bar: #1E4845;
    --kwugwo-sheen: rgba(255, 255, 255, 0.07);
    --kwugwo-hard: #000000;
    --kwugwo-backdrop: rgba(2, 10, 11, 0.7);
    --kwugwo-accent: #2DD4BF;
    --kwugwo-slab: var(--kwugwo-slab-reported, #1E4845);
  }
}
[data-kwugwo-root][data-theme="dark"] {
  --kwugwo-surface: #0B2326;
  --kwugwo-wash: #0F3331;
  --kwugwo-bar: #1E4845;
  --kwugwo-sheen: rgba(255, 255, 255, 0.07);
  --kwugwo-hard: #000000;
  --kwugwo-backdrop: rgba(2, 10, 11, 0.7);
  --kwugwo-accent: #2DD4BF;
  --kwugwo-slab: var(--kwugwo-slab-reported, #1E4845);
}

[data-kwugwo-root] [data-kwugwo-backdrop] {
  position: absolute;
  inset: 0;
  background: var(--kwugwo-backdrop);
  -webkit-backdrop-filter: blur(5px);
  backdrop-filter: blur(5px);
}

[data-kwugwo-root] [data-kwugwo-frame-wrap] {
  position: relative;
  width: 340px;
  max-width: 100%;
  height: 570px;
  max-height: 100vh;
  box-shadow: 14px 14px 0 var(--kwugwo-slab);
  transform: translate(0, 20px);
  transition:
    width 340ms var(--kwugwo-ease),
    height 340ms var(--kwugwo-ease),
    transform 220ms var(--kwugwo-ease);
}

[data-kwugwo-root] [data-kwugwo-frame-inner] {
  position: relative;
  width: 100%;
  height: 100%;
  overflow: hidden;
  background: var(--kwugwo-wash);
}
[data-kwugwo-root][data-open="true"] [data-kwugwo-frame-wrap] { transform: translate(0, 0); }

/* Once the iframe signals ready, the wrap expands to full modal size */
[data-kwugwo-root][data-ready="true"] [data-kwugwo-frame-wrap] {
  width: var(--kwugwo-frame-width);
  height: var(--kwugwo-frame-height);
}

/* Iframe is always rendered at the FINAL target dimensions so the hosted page
   boots at desktop viewport (no reflow when the wrap expands). The wrap clips
   it during loading; iframe is invisible until ready. It tracks the same
   height as the wrap so the hosted page's viewport matches the frame \u2014 that's
   what keeps long content from scrolling inside it. */
[data-kwugwo-root] iframe {
  position: absolute;
  top: 0;
  left: 0;
  width: var(--kwugwo-frame-width);
  height: var(--kwugwo-frame-height);
  border: 0;
  background: var(--kwugwo-surface);
  color-scheme: normal;
  visibility: hidden;
  display: block;
}
[data-kwugwo-root][data-ready="true"] iframe { visibility: visible; }

/* Close and countdown sit on the backdrop above the frame: square, outlined,
   and the close button lifts onto a hard shadow like the checkout's buttons. */
[data-kwugwo-root] [data-kwugwo-close] {
  position: absolute;
  top: -48px;
  right: 0;
  width: 38px;
  height: 38px;
  padding: 0;
  border-radius: 0;
  border: 1px solid rgba(255, 255, 255, 0.35);
  background: rgba(0, 25, 21, 0.35);
  color: #ffffff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition:
    background-color 0.15s,
    border-color 0.15s,
    transform 0.3s var(--kwugwo-bounce),
    box-shadow 0.3s var(--kwugwo-bounce);
}
[data-kwugwo-root] [data-kwugwo-close] svg {
  display: block;
  transition: transform 0.3s var(--kwugwo-bounce);
}
[data-kwugwo-root] [data-kwugwo-close]:focus-visible {
  outline: 3px solid var(--kwugwo-accent);
  outline-offset: 2px;
}
@media (hover: hover) {
  [data-kwugwo-root] [data-kwugwo-close]:hover,
  [data-kwugwo-root] [data-kwugwo-close]:focus-visible {
    background: rgba(0, 25, 21, 0.6);
    border-color: #ffffff;
    transform: translate(-2px, -2px);
    box-shadow: 3px 3px 0 var(--kwugwo-slab);
  }
  [data-kwugwo-root] [data-kwugwo-close]:hover svg,
  [data-kwugwo-root] [data-kwugwo-close]:focus-visible svg { transform: rotate(90deg); }
}
[data-kwugwo-root] [data-kwugwo-close]:active {
  transform: translate(0, 0);
  box-shadow: 0 0 0 var(--kwugwo-slab);
}

[data-kwugwo-root] [data-kwugwo-countdown] {
  position: absolute;
  top: -48px;
  left: 0;
  height: 38px;
  padding: 0 14px;
  border: 1px solid rgba(255, 255, 255, 0.35);
  background: rgba(0, 25, 21, 0.35);
  color: #ffffff;
  font-size: 13px;
  font-weight: 550;
  line-height: 1;
  font-variant-numeric: tabular-nums;
  pointer-events: none;
  display: none;
  align-items: center;
}
[data-kwugwo-root] [data-kwugwo-countdown][data-visible="true"] { display: inline-flex; }

/* Loader mirrors the hosted checkout's summary skeleton: the mint band with
   square bars where the merchant, amount and customer land, a sheen passing
   over them, and the reference at the bottom. */
[data-kwugwo-root] [data-kwugwo-loader] {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  padding: 2.5rem 2.25rem 2rem;
  background: var(--kwugwo-wash);
  pointer-events: none;
  z-index: 1;
  transition: opacity 250ms ease 80ms;
}

[data-kwugwo-root][data-ready="true"] [data-kwugwo-loader] {
  opacity: 0;
}

[data-kwugwo-root] [data-kwugwo-loader-bar] {
  position: relative;
  overflow: hidden;
  max-width: 100%;
  background: var(--kwugwo-bar);
}
[data-kwugwo-root] [data-kwugwo-loader-bar][data-push] { margin-top: auto; }
[data-kwugwo-root] [data-kwugwo-loader-bar]::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(105deg, transparent 30%, var(--kwugwo-sheen) 50%, transparent 70%);
  transform: translateX(-100%);
  animation: kwugwo-sheen 1.6s var(--kwugwo-ease) infinite;
}

@keyframes kwugwo-sheen {
  to { transform: translateX(100%); }
}

/* Phones and narrow windows: the checkout goes full screen, as the hosted
   page does, so there's no room (or need) for the slab. */
@media (max-width: 820px) {
  [data-kwugwo-root] [data-kwugwo-frame-wrap],
  [data-kwugwo-root][data-ready="true"] [data-kwugwo-frame-wrap] {
    width: 100%;
    max-width: none;
    height: 100%;
    max-height: none;
    box-shadow: none;
  }
  /* The iframe is sized for the desktop modal, but the full-screen mobile
     modal is taller than 720px on most phones. Without this the iframe stays
     720px tall and the frame-inner background shows as a band below it. */
  [data-kwugwo-root] iframe {
    width: 100%;
    height: 100%;
  }
  [data-kwugwo-root] [data-kwugwo-loader] {
    padding: 1.5rem 16px 1.25rem;
  }
  /* Over the page's own header now, so solid rather than see-through, and
     44px to stay an easy tap. */
  [data-kwugwo-root] [data-kwugwo-close] {
    top: 12px;
    right: 12px;
    width: 44px;
    height: 44px;
    background: rgba(0, 25, 21, 0.7);
  }
  [data-kwugwo-root] [data-kwugwo-countdown] {
    top: 12px;
    left: 12px;
    height: 44px;
    background: rgba(0, 25, 21, 0.7);
  }
}

@media (prefers-reduced-motion: reduce) {
  [data-kwugwo-root],
  [data-kwugwo-root] *,
  [data-kwugwo-root] *::after {
    transition-duration: 0.01ms !important;
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
  }
}

html[data-kwugwo-locked] { overflow: hidden !important; }
`;
    document.head.appendChild(style);
  }

  // src/overlay.ts
  var CROSS_PATH = "M548.203 537.6l289.099-289.098c9.998-9.998 9.998-26.206 0-36.205-9.997-9.997-26.206-9.997-36.203 0l-289.099 289.099-289.098-289.099c-9.998-9.997-26.206-9.997-36.205 0-9.997 9.998-9.997 26.206 0 36.205l289.099 289.098-289.099 289.099c-9.997 9.997-9.997 26.206 0 36.203 5 4.998 11.55 7.498 18.102 7.498s13.102-2.499 18.102-7.499l289.098-289.098 289.099 289.099c4.998 4.998 11.549 7.498 18.101 7.498s13.102-2.499 18.101-7.499c9.998-9.997 9.998-26.206 0-36.203l-289.098-289.098z";
  var SVG_NS = "http://www.w3.org/2000/svg";
  function crossIcon() {
    const svg = document.createElementNS(SVG_NS, "svg");
    svg.setAttribute("viewBox", "0 0 1024 1024");
    svg.setAttribute("width", "16");
    svg.setAttribute("height", "16");
    svg.setAttribute("fill", "currentColor");
    svg.setAttribute("aria-hidden", "true");
    const path = document.createElementNS(SVG_NS, "path");
    path.setAttribute("d", CROSS_PATH);
    svg.appendChild(path);
    return svg;
  }
  var HEX_COLOUR_RE = /^#[0-9a-fA-F]{6}$/;
  function createOverlay(iframeSrc, onCloseRequest) {
    injectStyles();
    const root = document.createElement("div");
    root.setAttribute("data-kwugwo-root", "");
    root.setAttribute("data-open", "false");
    root.setAttribute("data-ready", "false");
    root.setAttribute("role", "dialog");
    root.setAttribute("aria-modal", "true");
    root.setAttribute("aria-label", "Kwugwo Checkout");
    const backdrop = document.createElement("div");
    backdrop.setAttribute("data-kwugwo-backdrop", "");
    backdrop.addEventListener("click", () => onCloseRequest());
    const frameWrap = document.createElement("div");
    frameWrap.setAttribute("data-kwugwo-frame-wrap", "");
    const closeBtn = document.createElement("button");
    closeBtn.type = "button";
    closeBtn.setAttribute("data-kwugwo-close", "");
    closeBtn.setAttribute("aria-label", "Close checkout");
    closeBtn.appendChild(crossIcon());
    closeBtn.addEventListener("click", () => onCloseRequest());
    const countdown = document.createElement("div");
    countdown.setAttribute("data-kwugwo-countdown", "");
    countdown.setAttribute("role", "status");
    countdown.setAttribute("aria-live", "polite");
    const loader = document.createElement("div");
    loader.setAttribute("data-kwugwo-loader", "");
    const bars = [
      { height: 14, width: "62%", marginBottom: 14 },
      { height: 38, width: "80%", marginBottom: 28 },
      { height: 11, width: "64px", marginBottom: 10 },
      { height: 13, width: "96px", marginBottom: 10 },
      { height: 13, width: "170px" },
      { height: 11, width: "72%", push: true }
    ];
    bars.forEach(({ height, width, marginBottom, push }) => {
      const bar = document.createElement("div");
      bar.setAttribute("data-kwugwo-loader-bar", "");
      if (push) bar.setAttribute("data-push", "");
      bar.style.height = `${height}px`;
      bar.style.width = width;
      if (marginBottom) bar.style.marginBottom = `${marginBottom}px`;
      loader.appendChild(bar);
    });
    const iframe = document.createElement("iframe");
    iframe.src = iframeSrc;
    iframe.title = "Kwugwo Checkout";
    iframe.allow = "payment *";
    iframe.addEventListener("load", () => {
      root.setAttribute("data-ready", "true");
    });
    const frameInner = document.createElement("div");
    frameInner.setAttribute("data-kwugwo-frame-inner", "");
    frameInner.appendChild(iframe);
    frameInner.appendChild(loader);
    frameWrap.appendChild(countdown);
    frameWrap.appendChild(closeBtn);
    frameWrap.appendChild(frameInner);
    root.appendChild(backdrop);
    root.appendChild(frameWrap);
    document.body.appendChild(root);
    document.documentElement.setAttribute("data-kwugwo-locked", "");
    requestAnimationFrame(() => {
      root.setAttribute("data-open", "true");
    });
    const onKeyDown = (e) => {
      if (e.key === "Escape") onCloseRequest();
    };
    document.addEventListener("keydown", onKeyDown);
    return {
      root,
      iframe,
      onClose: onCloseRequest,
      markReady: () => root.setAttribute("data-ready", "true"),
      // The hosted page reports how tall its content actually is; the
      // stylesheet turns that into the frame height (floored at the default
      // modal height, capped at the viewport). Passing null restores the
      // default — used when nothing has been reported yet.
      setContentHeight: (height) => {
        if (height === null || !Number.isFinite(height) || height <= 0) {
          root.style.removeProperty("--kwugwo-content-height");
          return;
        }
        root.style.setProperty("--kwugwo-content-height", `${Math.ceil(height)}px`);
      },
      setCountdown: (seconds) => {
        if (seconds === null) {
          countdown.removeAttribute("data-visible");
          countdown.textContent = "";
          return;
        }
        const label = seconds === 1 ? "second" : "seconds";
        countdown.textContent = `Closing in ${seconds} ${label}\u2026`;
        countdown.setAttribute("data-visible", "true");
      },
      // The hosted page reports its theme and the accent-line colour it
      // is using (Kwugwo teal, or the processor's), so the frame's slab and
      // loader match what's inside. null falls back to the OS preference
      // and Kwugwo teal.
      setTheme: (theme, slab) => {
        if (theme === "light" || theme === "dark") root.setAttribute("data-theme", theme);
        else root.removeAttribute("data-theme");
        if (slab && HEX_COLOUR_RE.test(slab)) root.style.setProperty("--kwugwo-slab-reported", slab);
        else root.style.removeProperty("--kwugwo-slab-reported");
      },
      destroy: () => {
        document.removeEventListener("keydown", onKeyDown);
        root.setAttribute("data-open", "false");
        setTimeout(() => {
          root.remove();
          if (!document.querySelector("[data-kwugwo-root]")) {
            document.documentElement.removeAttribute("data-kwugwo-locked");
          }
        }, 200);
      }
    };
  }

  // src/checkout.ts
  var DEFAULT_BASE_URL = "https://checkout.kwugwo.africa";
  var UGWO_UID_RE = /^ugw\.[a-zA-Z0-9]{4}\.[a-zA-Z0-9_]{24}$/;
  var CLOSE_DELAY_SECONDS = 5;
  var DELAYED_CLOSE_ERROR_CODES = /* @__PURE__ */ new Set(["ugwo_processed", "ugwo_cancelled"]);
  var KwugwoCheckoutInstance = class {
    constructor(opts) {
      this.overlay = null;
      this.messageListener = null;
      if (!opts || typeof opts !== "object") {
        throw new Error("[Kwugwo] init() requires an options object.");
      }
      if (!opts.publicKey || typeof opts.publicKey !== "string" || !opts.publicKey.startsWith("pk.")) {
        throw new Error('[Kwugwo] init({ publicKey }) must be a string starting with "pk.".');
      }
      this.publicKey = opts.publicKey;
      this.baseUrl = (opts.baseUrl || DEFAULT_BASE_URL).replace(/\/+$/, "");
    }
    open(options) {
      if (!options || typeof options !== "object") {
        throw new Error("[Kwugwo] open() requires an options object.");
      }
      if (!options.ugwoUid || !UGWO_UID_RE.test(options.ugwoUid)) {
        throw new Error("[Kwugwo] open({ ugwoUid }) is required and must match ugw.XXXX.YYYY... format.");
      }
      if (typeof document === "undefined") {
        throw new Error("[Kwugwo] open() must be called in a browser environment.");
      }
      const merchantOrigin = window.location.origin;
      if (merchantOrigin === "null" || window.location.protocol === "file:") {
        throw new Error(
          "[Kwugwo] Cannot run on file:// \u2014 postMessage requires a real origin. Serve this page over HTTP (e.g. `npx serve .` or `python3 -m http.server`) and reload."
        );
      }
      if (this.overlay) {
        this.close();
      }
      const expectedOrigin = new URL(this.baseUrl).origin;
      const url = new URL(`${this.baseUrl}/${encodeURIComponent(options.ugwoUid)}`);
      url.searchParams.set("pk", this.publicKey);
      url.searchParams.set("embed", "1");
      url.searchParams.set("embed_origin", merchantOrigin);
      return new Promise((resolve) => {
        let settled = false;
        let countdownTimer = null;
        let finalizeCountdown = null;
        const closeAndResolve = (result) => {
          if (countdownTimer !== null) {
            clearTimeout(countdownTimer);
            countdownTimer = null;
          }
          finalizeCountdown = null;
          this.teardown();
          if (result.type === "success" && options.returnUrl) {
            window.location.href = options.returnUrl;
          }
          resolve(result);
        };
        const finish = async (result) => {
          if (settled) return;
          settled = true;
          try {
            if (result.type === "success" && options.onSuccess) {
              await options.onSuccess(result);
            } else if (result.type === "closed" && options.onClose) {
              options.onClose();
            } else if (result.type === "error" && options.onError) {
              options.onError(result);
            }
          } catch (err) {
            if (typeof console !== "undefined") {
              console.error("[Kwugwo] callback threw:", err);
            }
          }
          const countdownBeforeClose = result.type === "success" || result.type === "error" && DELAYED_CLOSE_ERROR_CODES.has(result.code);
          if (countdownBeforeClose) {
            let remaining = CLOSE_DELAY_SECONDS;
            this.overlay?.setCountdown(remaining);
            finalizeCountdown = () => closeAndResolve(result);
            const tick = () => {
              remaining -= 1;
              if (remaining <= 0) {
                closeAndResolve(result);
              } else {
                this.overlay?.setCountdown(remaining);
                countdownTimer = setTimeout(tick, 1e3);
              }
            };
            countdownTimer = setTimeout(tick, 1e3);
            return;
          }
          closeAndResolve(result);
        };
        const requestClose = () => {
          if (finalizeCountdown) {
            finalizeCountdown();
            return;
          }
          finish({ type: "closed", ugwoUid: options.ugwoUid });
        };
        this.overlay = createOverlay(url.toString(), requestClose);
        this.messageListener = (event) => {
          if (event.origin !== expectedOrigin) return;
          if (event.source !== this.overlay?.iframe.contentWindow) return;
          if (!isKwugwoMessage(event.data)) return;
          const payload = event.data.payload || {};
          switch (event.data.type) {
            case "ready":
              this.overlay?.markReady();
              break;
            case "resize":
              if (typeof payload.height === "number") {
                this.overlay?.setContentHeight(payload.height);
              }
              break;
            case "theme":
              this.overlay?.setTheme(
                payload.theme === "light" || payload.theme === "dark" ? payload.theme : null,
                typeof payload.slab === "string" ? payload.slab : null
              );
              break;
            case "success":
              finish({
                type: "success",
                ugwoUid: options.ugwoUid,
                activityUid: typeof payload.activityUid === "string" ? payload.activityUid : void 0
              });
              break;
            case "error":
              finish({
                type: "error",
                ugwoUid: options.ugwoUid,
                code: typeof payload.code === "string" ? payload.code : "unknown",
                message: typeof payload.message === "string" ? payload.message : "Checkout failed"
              });
              break;
            case "close":
              requestClose();
              break;
          }
        };
        window.addEventListener("message", this.messageListener);
      });
    }
    close() {
      if (this.overlay) {
        this.teardown();
      }
    }
    teardown() {
      if (this.messageListener) {
        window.removeEventListener("message", this.messageListener);
        this.messageListener = null;
      }
      if (this.overlay) {
        this.overlay.destroy();
        this.overlay = null;
      }
    }
  };
  var KwugwoCheckout = {
    init(opts) {
      return new KwugwoCheckoutInstance(opts);
    },
    MESSAGE_SOURCE
  };

  // src/global.ts
  var init = KwugwoCheckout.init;
  var MESSAGE_SOURCE2 = KwugwoCheckout.MESSAGE_SOURCE;
  return __toCommonJS(global_exports);
})();
