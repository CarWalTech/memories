import videojs from 'video.js';
_m.video.videojs = videojs;

import 'video.js/dist/video-js.min.css';

// The type defs for 'plyr' incorrectly use `export =` even though the package
// is real ESM with only a default export, which confuses default imports.
import * as PlyrModule from 'plyr';
const Plyr = (PlyrModule as unknown as { default: typeof globalThis.Plyr }).default;
_m.video.Plyr = Plyr;
import 'plyr/dist/plyr.css';

import plyrsvg from '@assets/plyr.svg';
(<any>Plyr).defaults.iconUrl = plyrsvg;
(<any>Plyr).defaults.blankVideo = '';
