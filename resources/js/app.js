//

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */

import './echo';

import hljs from 'highlight.js/lib/common';

/**
 * Highlights the lines of a unified diff, picking the language from the file extension
 * and falling back to auto-detection on the whole patch.
 */
window.highlightDiff = (pre, filename) => {
    const lines = [...pre.children];
    const extension = filename.split('.').pop().toLowerCase();
    const language = hljs.getLanguage(extension)
        ? extension
        : hljs.highlightAuto(lines.map((line) => line.textContent.slice(1)).join('\n')).language;

    if (!language) {
        return;
    }

    for (const line of lines) {
        const text = line.textContent;

        if (text.startsWith('@@')) {
            continue;
        }

        // shortcut: lines are highlighted one by one, so multi-line strings and comments lose their color after the first line.
        const prefix = document.createTextNode(text.charAt(0));
        line.innerHTML = hljs.highlight(text.slice(1), { language, ignoreIllegals: true }).value;
        line.prepend(prefix);
    }
};
