/**
 * A small Markdown renderer for chat replies.
 *
 * Everything is HTML-escaped before any markup is produced, so the only tags in
 * the output are the ones this file writes: a model can emit `<script>` and it
 * stays text on the page. Link targets are checked against a scheme allow-list
 * for the same reason, and anything else is left as the literal text it was.
 *
 * It covers what models actually reply with — fenced and inline code, headings,
 * lists, tables, quotes, rules, emphasis and links. Unfinished constructs render
 * as far as they go, so a reply still reads correctly halfway through streaming.
 */

/** Schemes a link in a reply is allowed to point at. */
const SAFE_LINK = /^(?:https?:\/\/|mailto:)/i;

/** Marks where a code span or link was lifted out of the text. */
const HOLE = '\u0000';

const HOLE_PATTERN = new RegExp(`${HOLE}(\\d+)${HOLE}`, 'g');

const FENCE = /^ {0,3}(`{3,}|~{3,})[ \t]*([^`]*)$/;
const HEADING = /^ {0,3}(#{1,6})[ \t]+(.*?)[ \t]*#*[ \t]*$/;
const RULE = /^ {0,3}([-*_])[ \t]*(?:\1[ \t]*){2,}$/;
const QUOTE = /^ {0,3}>[ \t]?(.*)$/;
const BULLET = /^([ \t]*)([-*+])[ \t]+(.*)$/;
const ORDERED = /^([ \t]*)(\d{1,9})[.)][ \t]+(.*)$/;
const DIVIDER =
    /^[ \t]*\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/;

type Alignment = 'left' | 'center' | 'right' | null;

/**
 * Render Markdown to HTML that is safe to hand to `v-html`.
 */
export function renderMarkdown(source: string): string {
    return renderBlocks(source.replace(/\r\n?/g, '\n').split('\n'));
}

function renderBlocks(lines: string[]): string {
    const html: string[] = [];
    let index = 0;

    while (index < lines.length) {
        const line = lines[index];

        if (line.trim() === '') {
            index += 1;

            continue;
        }

        const fence = FENCE.exec(line);

        if (fence !== null) {
            index = appendFence(html, lines, index, fence[1], fence[2]);

            continue;
        }

        const heading = HEADING.exec(line);

        if (heading !== null) {
            const level = heading[1].length;

            html.push(`<h${level}>${renderInline(heading[2])}</h${level}>`);
            index += 1;

            continue;
        }

        if (RULE.test(line)) {
            html.push('<hr>');
            index += 1;

            continue;
        }

        if (QUOTE.test(line)) {
            index = appendQuote(html, lines, index);

            continue;
        }

        if (BULLET.test(line) || ORDERED.test(line)) {
            index = appendList(html, lines, index);

            continue;
        }

        if (isTable(lines, index)) {
            index = appendTable(html, lines, index);

            continue;
        }

        index = appendParagraph(html, lines, index);
    }

    return html.join('');
}

/**
 * Whether a line opens a block, and so ends the paragraph before it.
 */
function opensBlock(line: string): boolean {
    return (
        FENCE.test(line) ||
        HEADING.test(line) ||
        RULE.test(line) ||
        QUOTE.test(line) ||
        BULLET.test(line) ||
        ORDERED.test(line)
    );
}

function appendParagraph(
    html: string[],
    lines: string[],
    start: number,
): number {
    const paragraph = [lines[start]];
    let index = start + 1;

    while (
        index < lines.length &&
        lines[index].trim() !== '' &&
        !opensBlock(lines[index]) &&
        !isTable(lines, index)
    ) {
        paragraph.push(lines[index]);
        index += 1;
    }

    html.push(`<p>${renderInline(paragraph.join('\n'))}</p>`);

    return index;
}

function appendFence(
    html: string[],
    lines: string[],
    start: number,
    marker: string,
    info: string,
): number {
    const closing = new RegExp(
        `^ {0,3}\\${marker[0]}{${marker.length},}[ \t]*$`,
    );
    const body: string[] = [];
    let index = start + 1;

    while (index < lines.length && !closing.test(lines[index])) {
        body.push(lines[index]);
        index += 1;
    }

    const language = /^[\w+#.-]+$/.test(info.trim().split(/\s+/)[0] ?? '')
        ? info.trim().split(/\s+/)[0]
        : '';
    const label = language === '' ? '' : ` data-language="${language}"`;

    html.push(`<pre${label}><code>${escapeHtml(body.join('\n'))}</code></pre>`);

    // Step over the closing fence. A block left open simply ends where the reply
    // does, which is exactly what a code block looks like mid-stream.
    return index < lines.length ? index + 1 : index;
}

function appendQuote(html: string[], lines: string[], start: number): number {
    const quoted: string[] = [];
    let index = start;

    while (index < lines.length) {
        const marked = QUOTE.exec(lines[index]);

        if (marked !== null) {
            quoted.push(marked[1]);
            index += 1;

            continue;
        }

        // A quote runs on through unmarked lines until something else starts.
        if (lines[index].trim() === '' || opensBlock(lines[index])) {
            break;
        }

        quoted.push(lines[index]);
        index += 1;
    }

    html.push(`<blockquote>${renderBlocks(quoted)}</blockquote>`);

    return index;
}

function appendList(html: string[], lines: string[], start: number): number {
    const ordered = ORDERED.test(lines[start]);
    const pattern = ordered ? ORDERED : BULLET;
    const opening = pattern.exec(lines[start]);

    if (opening === null) {
        return appendParagraph(html, lines, start);
    }

    const base = width(opening[1]);
    const items: string[][] = [];
    let index = start;
    let blanks = 0;

    while (index < lines.length) {
        const line = lines[index];

        if (line.trim() === '') {
            blanks += 1;

            // One blank line can sit inside a list; two end it.
            if (blanks > 1) {
                break;
            }

            index += 1;

            continue;
        }

        const item = pattern.exec(line);
        const indent = width(leading(line));

        if (item !== null && width(item[1]) <= base + 1) {
            items.push([item[3]]);
            blanks = 0;
            index += 1;

            continue;
        }

        const current = items[items.length - 1];

        // Anything indented past the marker belongs to the item above it,
        // nested lists included.
        if (current !== undefined && indent > base) {
            if (blanks > 0) {
                current.push('');
            }

            current.push(line.slice(Math.min(indent, base + 2)));
            blanks = 0;
            index += 1;

            continue;
        }

        break;
    }

    const rendered = items
        .map((item) => `<li>${renderItem(item)}</li>`)
        .join('');
    const first = Number(opening[2]);
    const from = ordered && first !== 1 ? ` start="${first}"` : '';

    html.push(ordered ? `<ol${from}>${rendered}</ol>` : `<ul>${rendered}</ul>`);

    return index;
}

/**
 * Render one list item.
 *
 * The opening run of text is rendered inline rather than as a paragraph, so a
 * tight list stays tight and an item carrying a nested list still reads as one
 * line followed by its children.
 */
function renderItem(content: string[]): string {
    let split = 1;

    while (
        split < content.length &&
        content[split].trim() !== '' &&
        !opensBlock(content[split]) &&
        !isTable(content, split)
    ) {
        split += 1;
    }

    const lead = renderInline(content.slice(0, split).join('\n'));
    const rest = content.slice(split);

    return rest.length === 0 ? lead : lead + renderBlocks(rest);
}

function isTable(lines: string[], index: number): boolean {
    const header = lines[index];
    const divider = lines[index + 1];

    return (
        header !== undefined &&
        divider !== undefined &&
        header.includes('|') &&
        divider.includes('-') &&
        DIVIDER.test(divider)
    );
}

function appendTable(html: string[], lines: string[], start: number): number {
    const headers = splitRow(lines[start]);
    const alignments = splitRow(lines[start + 1]).map(alignmentOf);
    const rows: string[][] = [];
    let index = start + 2;

    while (
        index < lines.length &&
        lines[index].trim() !== '' &&
        lines[index].includes('|')
    ) {
        rows.push(splitRow(lines[index]));
        index += 1;
    }

    const head = headers
        .map(
            (cell, column) =>
                `<th${styleOf(alignments[column])}>${renderInline(cell)}</th>`,
        )
        .join('');
    const body = rows
        .map((row) => {
            const cells = headers
                .map(
                    (_header, column) =>
                        `<td${styleOf(alignments[column])}>${renderInline(row[column] ?? '')}</td>`,
                )
                .join('');

            return `<tr>${cells}</tr>`;
        })
        .join('');

    // A wide table has to scroll inside the message rather than stretch it.
    html.push(
        `<div class="md-scroll"><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div>`,
    );

    return index;
}

function alignmentOf(cell: string): Alignment {
    const left = cell.startsWith(':');
    const right = cell.endsWith(':');

    if (left && right) {
        return 'center';
    }

    if (right) {
        return 'right';
    }

    return left ? 'left' : null;
}

function styleOf(alignment: Alignment): string {
    return alignment === null || alignment === undefined
        ? ''
        : ` style="text-align:${alignment}"`;
}

/**
 * Split a table row into cells, honouring `\|` inside one.
 */
function splitRow(row: string): string[] {
    return row
        .trim()
        .replace(/\\\|/g, '\u0001')
        .replace(/^\|/, '')
        .replace(/\|$/, '')
        .split('|')
        .map((cell) => cell.trim().replace(/\u0001/g, '|'));
}

function renderInline(text: string): string {
    const holes: string[] = [];

    let out = escapeHtml(text).replace(
        /(`+)([^\n]+?)\1/g,
        (_match, _ticks: string, code: string) =>
            hole(holes, `<code>${code.trim()}</code>`),
    );

    out = out.replace(
        /\[([^\]\n]*)\]\(([^()\s]+)\)/g,
        (match, label: string, target: string) =>
            SAFE_LINK.test(target)
                ? hole(holes, anchor(target, renderEmphasis(label) || target))
                : match,
    );

    out = out.replace(
        /(^|[\s(])(https?:\/\/[^\s<]*[^\s<.,:;!?)\]])/g,
        (_match, before: string, url: string) =>
            `${before}${hole(holes, anchor(url, url))}`,
    );

    out = renderEmphasis(out).replace(/\n/g, '<br>');

    return fill(out, holes);
}

function renderEmphasis(text: string): string {
    return text
        .replace(
            /\*\*\*(?=\S)([\s\S]*?\S)\*\*\*/g,
            '<strong><em>$1</em></strong>',
        )
        .replace(/\*\*(?=\S)([\s\S]*?\S)\*\*/g, '<strong>$1</strong>')
        .replace(/~~(?=\S)([\s\S]*?\S)~~/g, '<del>$1</del>')
        .replace(/(^|[^\w*])\*(?=\S)([^*\n]*?\S)\*(?![\w*])/g, '$1<em>$2</em>')
        .replace(
            /(^|[^\w_])__(?=\S)([\s\S]*?\S)__(?![\w_])/g,
            '$1<strong>$2</strong>',
        )
        .replace(/(^|[^\w_])_(?=\S)([^_\n]*?\S)_(?![\w_])/g, '$1<em>$2</em>');
}

function anchor(target: string, label: string): string {
    return `<a href="${target}" target="_blank" rel="noopener noreferrer nofollow">${label}</a>`;
}

function hole(holes: string[], html: string): string {
    holes.push(html);

    return `${HOLE}${holes.length - 1}${HOLE}`;
}

/**
 * Put the lifted-out pieces back. A link can hold a code span, so this runs
 * until nothing is left to fill rather than exactly once.
 */
function fill(text: string, holes: string[]): string {
    let out = text;

    for (let pass = 0; pass < 3 && out.includes(HOLE); pass += 1) {
        out = out.replace(
            HOLE_PATTERN,
            (_match, index: string) => holes[Number(index)] ?? '',
        );
    }

    return out;
}

function escapeHtml(text: string): string {
    return (
        text
            // Control characters are dropped first: one of them is how this file
            // marks a lifted-out span, and text must not be able to forge one.
            .replace(/[\u0000-\u0008\u000b-\u001f]/g, '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
    );
}

function leading(line: string): string {
    return /^[ \t]*/.exec(line)?.[0] ?? '';
}

/** Indentation width, counting a tab as two columns. */
function width(indent: string): number {
    return indent.replace(/\t/g, '  ').length;
}
