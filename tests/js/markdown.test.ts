import assert from 'node:assert/strict';
import { test } from 'node:test';
import { renderMarkdown } from '../../resources/js/lib/markdown.ts';

test('paragraphs keep their line breaks', () => {
    assert.equal(renderMarkdown('one\ntwo'), '<p>one<br>two</p>');
    assert.equal(renderMarkdown('one\n\ntwo'), '<p>one</p><p>two</p>');
});

test('headings render at their level', () => {
    assert.equal(renderMarkdown('# Title'), '<h1>Title</h1>');
    assert.equal(renderMarkdown('### Deeper ###'), '<h3>Deeper</h3>');
    // Without the space it is not a heading.
    assert.equal(renderMarkdown('#NotATitle'), '<p>#NotATitle</p>');
});

test('emphasis renders, and does not eat snake_case or maths', () => {
    assert.equal(renderMarkdown('**bold**'), '<p><strong>bold</strong></p>');
    assert.equal(renderMarkdown('*italic*'), '<p><em>italic</em></p>');
    assert.equal(
        renderMarkdown('***both***'),
        '<p><strong><em>both</em></strong></p>',
    );
    assert.equal(renderMarkdown('~~gone~~'), '<p><del>gone</del></p>');
    assert.equal(renderMarkdown('__bold__'), '<p><strong>bold</strong></p>');
    assert.equal(renderMarkdown('snake_case_name'), '<p>snake_case_name</p>');
    assert.equal(renderMarkdown('2 * 3 * 4'), '<p>2 * 3 * 4</p>');
});

test('inline code is left exactly as written', () => {
    assert.equal(
        renderMarkdown('use `**not bold**` here'),
        '<p>use <code>**not bold**</code> here</p>',
    );
    assert.equal(renderMarkdown('``a ` b``'), '<p><code>a ` b</code></p>');
});

test('fenced code blocks keep their content and record the language', () => {
    assert.equal(
        renderMarkdown('```php\n$a = 1;\n```'),
        '<pre data-language="php"><code>$a = 1;</code></pre>',
    );
    assert.equal(
        renderMarkdown('```\nplain\n```'),
        '<pre><code>plain</code></pre>',
    );
    // Blank lines and markdown inside a fence survive untouched.
    assert.equal(
        renderMarkdown('```\n# not a heading\n\n- not a list\n```'),
        '<pre><code># not a heading\n\n- not a list</code></pre>',
    );
});

test('an unclosed fence renders what has arrived so far', () => {
    assert.equal(
        renderMarkdown('```js\nconst a = 1;'),
        '<pre data-language="js"><code>const a = 1;</code></pre>',
    );
});

test('lists render, including nesting and ordered starts', () => {
    assert.equal(
        renderMarkdown('- one\n- two'),
        '<ul><li>one</li><li>two</li></ul>',
    );
    assert.equal(
        renderMarkdown('1. one\n2. two'),
        '<ol><li>one</li><li>two</li></ol>',
    );
    assert.equal(
        renderMarkdown('3. three\n4. four'),
        '<ol start="3"><li>three</li><li>four</li></ol>',
    );
    assert.equal(
        renderMarkdown('- outer\n  - inner'),
        '<ul><li>outer<ul><li>inner</li></ul></li></ul>',
    );
});

test('a list interrupts the paragraph above it', () => {
    assert.equal(
        renderMarkdown('Steps:\n- one'),
        '<p>Steps:</p><ul><li>one</li></ul>',
    );
});

test('block quotes render, and nest', () => {
    assert.equal(
        renderMarkdown('> quoted'),
        '<blockquote><p>quoted</p></blockquote>',
    );
    assert.equal(
        renderMarkdown('> > deep'),
        '<blockquote><blockquote><p>deep</p></blockquote></blockquote>',
    );
});

test('horizontal rules render', () => {
    assert.equal(renderMarkdown('---'), '<hr>');
    assert.equal(renderMarkdown('***'), '<hr>');
});

test('tables render with alignment and scroll on their own', () => {
    const html = renderMarkdown('| a | b |\n| :-- | --: |\n| 1 | 2 |');

    assert.ok(html.startsWith('<div class="md-scroll"><table>'));
    assert.ok(html.includes('<th style="text-align:left">a</th>'));
    assert.ok(html.includes('<th style="text-align:right">b</th>'));
    assert.ok(html.includes('<td style="text-align:left">1</td>'));
});

test('a short table row is padded rather than shifting cells', () => {
    const html = renderMarkdown('| a | b |\n| - | - |\n| 1 |');

    assert.ok(html.includes('<tr><td>1</td><td></td></tr>'));
});

test('links render only for schemes we allow', () => {
    assert.equal(
        renderMarkdown('[docs](https://example.com)'),
        '<p><a href="https://example.com" target="_blank" rel="noopener noreferrer nofollow">docs</a></p>',
    );
    // A javascript: target is not a link, it is text.
    assert.equal(
        renderMarkdown('[click](javascript:alert(1))'),
        '<p>[click](javascript:alert(1))</p>',
    );
    assert.ok(!renderMarkdown('[x](JaVaScRiPt:alert(1))').includes('<a '));
});

test('bare urls become links', () => {
    assert.equal(
        renderMarkdown('see https://example.com/a_b now'),
        '<p>see <a href="https://example.com/a_b" target="_blank" rel="noopener noreferrer nofollow">https://example.com/a_b</a> now</p>',
    );
    // Trailing punctuation belongs to the sentence, not the url.
    assert.ok(
        renderMarkdown('at https://example.com.').includes(
            '>https://example.com</a>.',
        ),
    );
});

test('html in a reply is text, never markup', () => {
    assert.equal(
        renderMarkdown('<script>alert(1)</script>'),
        '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>',
    );
    assert.equal(
        renderMarkdown('<img src=x onerror=alert(1)>'),
        '<p>&lt;img src=x onerror=alert(1)&gt;</p>',
    );
    assert.equal(
        renderMarkdown('```\n<script>alert(1)</script>\n```'),
        '<pre><code>&lt;script&gt;alert(1)&lt;/script&gt;</code></pre>',
    );
});

test('a link label cannot break out of its attribute', () => {
    const html = renderMarkdown('[x](https://a.com"onmouseover="alert(1))');

    assert.ok(!html.includes('onmouseover="alert'));
    assert.ok(!html.includes('" onmouseover'));
});

test('text cannot forge the marker used to lift out code spans', () => {
    const html = renderMarkdown('a \u00000\u0000 b `real`');

    assert.ok(html.includes('<code>real</code>'));
    assert.equal(html, '<p>a 0 b <code>real</code></p>');
});

test('a reply mixing several blocks holds together', () => {
    const html = renderMarkdown(
        '## Result\n\nIt is **fine**.\n\n```sh\nnpm run build\n```\n\n1. first\n2. second\n\n> note\n',
    );

    assert.equal(
        html,
        '<h2>Result</h2>' +
            '<p>It is <strong>fine</strong>.</p>' +
            '<pre data-language="sh"><code>npm run build</code></pre>' +
            '<ol><li>first</li><li>second</li></ol>' +
            '<blockquote><p>note</p></blockquote>',
    );
});

test('empty input renders nothing', () => {
    assert.equal(renderMarkdown(''), '');
    assert.equal(renderMarkdown('   \n\n'), '');
});

test('every prefix of a reply renders, since replies arrive a piece at a time', () => {
    const reply =
        '## Steps\n\n1. Run `npm ci`\n2. Then:\n\n```sh\nnpm run build\n```\n\n' +
        '| a | b |\n| - | - |\n| 1 | 2 |\n\n> Careful with <b>this</b> & *that*\n\n' +
        'See [docs](https://example.com) — done 😀';

    for (let length = 0; length <= reply.length; length += 1) {
        const partial = reply.slice(0, length);
        let html = '';

        assert.doesNotThrow(() => {
            html = renderMarkdown(partial);
        }, `failed at ${length} characters`);

        // Half-written markup must never turn into markup of its own.
        assert.ok(!html.includes('<b>'), `raw tag leaked at ${length}`);
        assert.ok(!html.includes('\u0000'), `marker leaked at ${length}`);
    }
});
