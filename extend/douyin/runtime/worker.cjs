'use strict';

const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { performance } = require('node:perf_hooks');
const crypto = require('node:crypto');
const { webcrypto } = crypto;

const USER_AGENT =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
  '(KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36';
const BUNDLE_PATH = path.join(__dirname, 'vendor', 'bdms.js');
const BUNDLE = fs.readFileSync(BUNDLE_PATH, 'utf8');
const DTRAIT_BUNDLE_PATH = path.join(__dirname, 'vendor', 'dtrait.js');
const DTRAIT_BUNDLE = fs.readFileSync(DTRAIT_BUNDLE_PATH, 'utf8');
const LOGIN_ORIGIN = 'https://login.douyin.com';
const DTRAIT_CONFIG = {
  centralRsaPub:
    'LS0tLS1CRUdJTiBSU0EgUFVCTElDIEtFWS0tLS0tCk1JSUJDZ0tDQVFFQTQrZHZ2WTd1TStvcGMrbkxHL0R1bVNlRm83YVZjSW0xTE8rbVVJcldwclJ6UDBhMUdwRVEKNHF0TzlNUmYvbHdFSXgzOCs0Qlo0WE9HemV2VnR1VXZmSU9VRTdBVHRRVzdGS0pmNVBuU0xDSTYvazB2bDFGQwpMVVNWbUVQNnFQSnJJalo0elhvcWkzeXVOWisxb2RiUkEvL0dIZ2NnU3l5eWFMcXp3amtwV0dYb3VNWW12WXNTCnBway9mdjJFV0FCc3RQTnhXYTRFT0JDYWRUVVBrWE5RNzZOQkVQOXh6ZkpTMjB3aUR2MW9TL3ZLdnJTVXBXY0oKbmF6a2tCdnFRYmJBcVZiUUZURi9EUGlrcHB1NlpUNmxHSVh2SktDcmVlRmlIQTJxSzZ0UzE4U1dWSFc5QVJ6MQorcGpCMWVxSUlZdG9oV3BUMkI0ME9DNE84dFZlQkFuYmlRSURBUUFCCi0tLS0tRU5EIFJTQSBQVUJMSUMgS0VZLS0tLS0=',
  centralVersion: 'd0',
  edgeRsaPub:
    'LS0tLS1CRUdJTiBSU0EgUFVCTElDIEtFWS0tLS0tCk1JSUJDZ0tDQVFFQXlFQkQ0MXQzcWpqL1NOaU5rT3BBbnNGdGZKZ0F5MGF5VTZCbEJ3RS9EZVZjNkdWV0xWUk4KWjdiMWRuRHVmQk5iUm1XQjlZeWVyYm1FOFFDM2lPOXp1NVFWd2x4SGV2ZEN0ZFFyeDZpQzF3QVRoaHFjdTNIYgprZ1dsazZ1Ylk5MXRvRFhNd0k2WGdmRUoyVEJsdHVSbklXRjR5RDVEaEc2c3lSSVNmNTRMWGY0WjgzbzlGcXNvCmlsNkV3cVZCbEU3dXlIY3dJOTA5WDg4Rlc3MXFLdmJMU040OGJlQ0EwbzFmZitqbmhRakNBTDZqbUR2dUhJeWEKUk1vYm1wRFVOLzQ3L3NHbDNzNDlFOEZFSEFXUmk5d1cyc2NZUDBJTkJXUlR5RlRHcG9GUGlqekJFUndnYzdrWQozVno3ZytSMXd2RkxUSEVITEtYUWFwTHpEMWR5Uk81YUt3SURBUUFCCi0tLS0tRU5EIFJTQSBQVUJMSUMgS0VZLS0tLS0K',
  edgeVersion: 'd0',
  urlVersion: '1.0.0.16',
  dTraitVersion: '0',
};

class EventTargetShim {
  constructor() {
    this.listeners = new Map();
  }

  addEventListener(type, listener) {
    const listeners = this.listeners.get(type) || [];
    listeners.push(listener);
    this.listeners.set(type, listeners);
  }

  removeEventListener(type, listener) {
    const listeners = this.listeners.get(type) || [];
    this.listeners.set(type, listeners.filter((candidate) => candidate !== listener));
  }

  dispatchEvent(event) {
    for (const listener of this.listeners.get(event.type) || []) {
      listener.call(this, event);
    }
    return true;
  }
}

class StorageShim {
  constructor() {
    this.values = new Map();
  }

  get length() {
    return this.values.size;
  }

  key(index) {
    return [...this.values.keys()][index] ?? null;
  }

  getItem(key) {
    return this.values.has(String(key)) ? this.values.get(String(key)) : null;
  }

  setItem(key, value) {
    this.values.set(String(key), String(value));
  }

  removeItem(key) {
    this.values.delete(String(key));
  }

  clear() {
    this.values.clear();
  }
}

function inertObject(overrides = {}) {
  const target = function inertTarget() {};
  Object.assign(target, overrides);
  return new Proxy(target, {
    get(object, property) {
      if (property === Symbol.toPrimitive) return () => '';
      if (property === Symbol.iterator) return function* iterator() {};
      if (property === 'then') return undefined;
      if (property in object) return object[property];
      return inertObject();
    },
    construct() {
      return inertObject();
    },
    apply() {
      return inertObject();
    },
  });
}

function createRuntimeConsole(seed) {
  const normalizeDtraitParams = (...args) => {
    const params = args[0] === '[params]' ? args[1] : null;
    if (!params || typeof params !== 'object' || !params.str || typeof params.str !== 'object') return;
    for (const key of Object.keys(params.str)) {
      if (params.str[key] === undefined) {
        params.str[key] = crypto
          .createHash('sha256')
          .update(`${USER_AGENT}|1920x1080|${seed}|${key}`)
          .digest()
          .readUInt32BE(0);
      }
    }
  };
  return {
    log: normalizeDtraitParams,
    info() {},
    warn() {},
    error() {},
    debug() {},
  };
}

function createDtraitHelpers(keyHex) {
  const aesKey = Buffer.from(keyHex, 'hex');
  return {
    cryptoUtil: {
      bufferConcat(parts) {
        return new Uint8Array(Buffer.concat(parts.map((part) => Buffer.from(part))));
      },
      uint8ArrayToHex(value) {
        return Buffer.from(value).toString('hex');
      },
    },
    aes: {
      getAesKey() {
        return new Uint8Array(aesKey);
      },
      async encryptData(requestKeyHex, plaintext) {
        const requestKey = Buffer.from(String(requestKeyHex), 'hex');
        const iv = crypto.randomBytes(16);
        const cipher = crypto.createCipheriv('aes-128-cbc', requestKey, iv);
        const encrypted = Buffer.concat([cipher.update(String(plaintext), 'utf8'), cipher.final()]);
        return {
          cipherText: Buffer.concat([iv, encrypted]).toString('base64'),
          encryptedData: encrypted.toString('base64'),
          iv: iv.toString('base64'),
        };
      },
    },
    rsa: {
      async encryptData(publicKey, plaintext) {
        return crypto.publicEncrypt(
          { key: String(publicKey), padding: crypto.constants.RSA_PKCS1_PADDING },
          Buffer.from(String(plaintext)),
        ).toString('base64');
      },
    },
  };
}

class ElementShim extends EventTargetShim {
  constructor(tagName) {
    super();
    this.tagName = String(tagName).toUpperCase();
    this.nodeName = this.tagName;
    this.nodeType = 1;
    this.style = {};
    this.children = [];
    this.attributes = new Map();
    this.parentNode = null;
    this.innerHTML = '';
    this.textContent = '';
    this.id = '';
    this.width = 300;
    this.height = 150;
  }

  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    if (typeof child.onload === 'function') queueMicrotask(() => child.onload());
    return child;
  }

  removeChild(child) {
    this.children = this.children.filter((candidate) => candidate !== child);
    child.parentNode = null;
    return child;
  }

  setAttribute(name, value) {
    this.attributes.set(String(name), String(value));
    this[name] = String(value);
  }

  getAttribute(name) {
    return this.attributes.get(String(name)) ?? null;
  }

  getBoundingClientRect() {
    return { x: 0, y: 0, top: 0, left: 0, right: 300, bottom: 150, width: 300, height: 150 };
  }

  getContext() {
    if (this.tagName !== 'CANVAS') return null;
    return inertObject({
      canvas: this,
      getImageData: () => ({ data: new Uint8ClampedArray(4), width: 1, height: 1 }),
      getParameter: () => '',
      getSupportedExtensions: () => [],
      measureText: () => ({ width: 0 }),
    });
  }

  toDataURL() {
    return 'data:image/png;base64,iVBORw0KGgo=';
  }
}

class ImageShim extends EventTargetShim {
  constructor() {
    super();
    this.width = 0;
    this.height = 0;
    this._src = '';
  }

  get src() {
    return this._src;
  }

  set src(value) {
    this._src = String(value);
    queueMicrotask(() => {
      this.dispatchEvent({ type: 'load', target: this });
      if (typeof this.onload === 'function') this.onload();
    });
  }
}

class DocumentShim extends EventTargetShim {
  constructor(location) {
    super();
    this.location = location;
    this.visibilityState = 'visible';
    this.hidden = false;
    this.readyState = 'complete';
    this.documentElement = new ElementShim('html');
    this.head = new ElementShim('head');
    this.body = new ElementShim('body');
    this.documentElement.appendChild(this.head);
    this.documentElement.appendChild(this.body);
    this.cookieValues = new Map();
  }

  get cookie() {
    return [...this.cookieValues].map(([name, value]) => `${name}=${value}`).join('; ');
  }

  set cookie(serialized) {
    const pair = String(serialized).split(';', 1)[0];
    const separator = pair.indexOf('=');
    if (separator < 0) return;
    this.cookieValues.set(pair.slice(0, separator).trim(), pair.slice(separator + 1));
  }

  createElement(tagName) {
    return new ElementShim(tagName);
  }

  createTextNode(text) {
    return { nodeType: 3, textContent: String(text) };
  }

  getElementById() {
    return null;
  }

  getElementsByTagName(name) {
    if (String(name).toLowerCase() === 'head') return [this.head];
    if (String(name).toLowerCase() === 'body') return [this.body];
    return [];
  }

  querySelector() {
    return null;
  }

  querySelectorAll() {
    return [];
  }
}

function createContext(seed = '') {
  const requests = [];
  const location = new URL('https://www.douyin.com/');
  const document = new DocumentShim(location);

  class XMLHttpRequestShim extends EventTargetShim {
    static DONE = 4;

    constructor() {
      super();
      this.readyState = 0;
      this.status = 204;
      this.responseText = '';
      this.response = '';
      this.responseType = '';
      this.requestHeaders = {};
    }

    open(method, url, async = true) {
      this.method = String(method).toUpperCase();
      this.url = String(url);
      this.async = async !== false;
      this.readyState = 1;
    }

    setRequestHeader(name, value) {
      this.requestHeaders[String(name).toLowerCase()] = String(value);
    }

    getAllResponseHeaders() {
      return '';
    }

    getResponseHeader() {
      return null;
    }

    send(body = null) {
      requests.push({
        method: this.method,
        url: this.url,
        headers: { ...this.requestHeaders },
        body: body === null ? '' : String(body),
      });
      queueMicrotask(() => {
        this.readyState = XMLHttpRequestShim.DONE;
        this.dispatchEvent({ type: 'readystatechange', target: this });
        this.dispatchEvent({ type: 'load', target: this });
        this.dispatchEvent({ type: 'loadend', target: this });
        if (typeof this.onreadystatechange === 'function') this.onreadystatechange();
        if (typeof this.onload === 'function') this.onload();
        if (typeof this.onloadend === 'function') this.onloadend();
      });
    }

    abort() {}
  }

  const navigator = {
    appCodeName: 'Mozilla',
    appName: 'Netscape',
    appVersion: USER_AGENT.replace('Mozilla/', ''),
    userAgent: USER_AGENT,
    platform: 'Win32',
    product: 'Gecko',
    productSub: '20030107',
    vendor: 'Google Inc.',
    vendorSub: '',
    language: 'zh-CN',
    languages: ['zh-CN', 'zh'],
    cookieEnabled: true,
    doNotTrack: null,
    hardwareConcurrency: 8,
    deviceMemory: 8,
    maxTouchPoints: 0,
    webdriver: false,
    onLine: true,
    plugins: Array.from({ length: 5 }, () => inertObject()),
    mimeTypes: [],
    connection: { downlink: 10, effectiveType: '4g', rtt: 50, saveData: false },
    storage: { estimate: async () => ({ quota: 1073741824, usage: 0 }) },
    permissions: { query: async () => ({ state: 'prompt' }) },
  };

  const window = new EventTargetShim();
  Object.assign(window, {
    window,
    self: window,
    top: window,
    parent: window,
    frames: window,
    globalThis: window,
    document,
    navigator,
    clientInformation: navigator,
    location,
    origin: location.origin,
    history: { length: 1, state: null, pushState() {}, replaceState() {} },
    screen: {
      width: 1920,
      height: 1080,
      availWidth: 1920,
      availHeight: 1040,
      colorDepth: 24,
      pixelDepth: 24,
      orientation: { angle: 0, type: 'landscape-primary' },
    },
    innerWidth: 1920,
    innerHeight: 947,
    outerWidth: 1920,
    outerHeight: 1040,
    devicePixelRatio: 1,
    localStorage: new StorageShim(),
    sessionStorage: new StorageShim(),
    performance,
    crypto: webcrypto,
    XMLHttpRequest: XMLHttpRequestShim,
    Image: ImageShim,
    fetch: async () => new Response('{}', { status: 204, headers: { 'content-type': 'application/json' } }),
    URL,
    URLSearchParams,
    Headers,
    Request,
    Response,
    FormData,
    Blob,
    TextEncoder,
    TextDecoder,
    Uint8Array,
    Uint16Array,
    Uint32Array,
    Int8Array,
    Int16Array,
    Int32Array,
    Uint8ClampedArray,
    ArrayBuffer,
    DataView,
    atob: (value) => Buffer.from(String(value), 'base64').toString('binary'),
    btoa: (value) => Buffer.from(String(value), 'binary').toString('base64'),
    setTimeout,
    clearTimeout,
    setInterval,
    clearInterval,
    queueMicrotask,
    requestAnimationFrame: (callback) => setTimeout(() => callback(performance.now()), 16),
    cancelAnimationFrame: clearTimeout,
    getComputedStyle: () => inertObject(),
    matchMedia: () => ({ matches: false, media: '', addListener() {}, removeListener() {} }),
    chrome: inertObject({ runtime: {} }),
    console: createRuntimeConsole(seed),
  });

  return { context: vm.createContext(window), requests, window };
}

function initializeDtrait(window, context, keyHex) {
  const helpers = createDtraitHelpers(keyHex);
  window.DTraitUcCryptoJSUtil = helpers.cryptoUtil;
  window.DTraitUcAesEncrypt = helpers.aes;
  window.DTraitUcRsaEncrypt = helpers.rsa;
  vm.runInContext(DTRAIT_BUNDLE, context, { filename: DTRAIT_BUNDLE_PATH, timeout: 15000 });

  const Dtrait = window.DTraitSDK && (window.DTraitSDK.default || window.DTraitSDK);
  if (!Dtrait || typeof Dtrait.getInstance !== 'function') {
    throw new Error('DTrait bundle did not initialize');
  }
  Dtrait.getInstance(DTRAIT_CONFIG, {
    dTraitPath: ['/passport'],
    dTraitHost: [],
    urlRewriteRules: [],
    containerSdkVersion: '1.1.3',
    libraGroup: '',
    delayCollect: 0,
  });
}

function validateInput(input) {
  const method = String(input.method || 'GET').toUpperCase();
  if (!['GET', 'POST'].includes(method)) throw new Error('method must be GET or POST');

  const url = new URL(String(input.url || ''));
  if (url.origin !== LOGIN_ORIGIN || !url.pathname.startsWith('/passport/')) {
    throw new Error('only login.douyin.com/passport/ URLs are accepted');
  }
  if (url.searchParams.has('a_bogus')) throw new Error('URL is already signed');

  const body = input.body === undefined || input.body === null ? '' : String(input.body);
  if (url.href.length > 65536 || Buffer.byteLength(body) > 1048576) {
    throw new Error('request exceeds signer limits');
  }
  const includeDtrait = input.include_dtrait === true;
  const dtraitKey = String(input.dtrait_key || '');
  if (includeDtrait && !/^[a-f0-9]{32}$/i.test(dtraitKey)) {
    throw new Error('dtrait_key must contain exactly 32 hexadecimal characters');
  }
  return { method, url: url.href, body, includeDtrait, dtraitKey: dtraitKey.toLowerCase() };
}

function assertDtraitHeader(value) {
  const parts = String(value || '').split('_');
  if (
    parts.length !== 3 ||
    parts[0] !== 'd0' ||
    !/^[A-Za-z0-9+/]+={0,2}$/.test(parts[1]) ||
    !/^[A-Za-z0-9+/]+={0,2}$/.test(parts[2]) ||
    Buffer.from(parts[1], 'base64').length !== 256 ||
    Buffer.from(parts[2], 'base64').length < 256
  ) {
    throw new Error('DTrait result has an invalid structure');
  }
}

async function sign(input) {
  const request = validateInput(input);
  const { context, requests, window } = createContext(request.dtraitKey);
  vm.runInContext(BUNDLE, context, { filename: BUNDLE_PATH, timeout: 15000 });
  if (!window.bdms || typeof window.bdms.init !== 'function') {
    throw new Error('BDMS bundle did not initialize');
  }

  const initialized = window.bdms.init({ aid: 6383, pageId: 1, paths: ['/passport/'], boe: false });
  if (initialized && typeof initialized.then === 'function') {
    await Promise.race([initialized, new Promise((resolve) => setTimeout(resolve, 1000))]);
  }
  await new Promise((resolve) => setTimeout(resolve, 25));

  if (request.includeDtrait) {
    initializeDtrait(window, context, request.dtraitKey);
    await new Promise((resolve) => setTimeout(resolve, 75));
  }

  const xhr = new window.XMLHttpRequest();
  xhr.open(request.method, request.url, true);
  xhr.setRequestHeader('accept', 'application/json, text/javascript');
  if (request.method === 'POST') {
    xhr.setRequestHeader('content-type', 'application/x-www-form-urlencoded');
  }
  xhr.send(request.body || null);
  await new Promise((resolve) => setTimeout(resolve, 25));

  const signed = [...requests]
    .reverse()
    .find((candidate) => candidate.url.startsWith(LOGIN_ORIGIN + '/passport/'));
  if (!signed) throw new Error('BDMS did not emit the passport request');

  const signedUrl = new URL(signed.url);
  if (signedUrl.origin !== LOGIN_ORIGIN || !signedUrl.searchParams.get('a_bogus')) {
    throw new Error('BDMS result is missing a_bogus');
  }
  const headers = {};
  if (request.includeDtrait) {
    const dtrait = String(signed.headers['x-tt-session-dtrait'] || '');
    assertDtraitHeader(dtrait);
    headers['X-TT-Session-Dtrait'] = dtrait;
  }
  return { url: signedUrl.href, headers };
}

async function main() {
  const raw = fs.readFileSync(0, 'utf8').trim();
  if (!raw) throw new Error('expected a JSON request on stdin');
  const result = await sign(JSON.parse(raw));
  process.stdout.write(`${JSON.stringify(result)}\n`);
}

main()
  .then(() => process.exit(0))
  .catch((error) => {
    process.stderr.write(`${error && error.stack ? error.stack : error}\n`);
    process.exit(1);
  });
