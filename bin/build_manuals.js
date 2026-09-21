/* docs/manual-*.md から A4縦のマニュアル（.pptx）を作る。
 *
 *   npm install          （初回のみ。pptxgenjs を入れる）
 *   node bin/build_manuals.js
 *
 * マニュアルの原稿は Markdown（docs/manual-*.md）が正で、pptx は生成物。
 * 文章を直すときは .md を編集して、このスクリプトを実行し直す。
 *
 * 原稿の書き方（独自の簡易記法）
 * ------------------------------------------------------------------
 *   # タイトル            表紙（1ファイルに1つ）
 *   > 表紙の下に出る一文（# の直後に書く）
 *   ## ページ見出し        ここから新しいページ
 *   ### 小見出し
 *   1. 手順                番号付きの手順（丸囲みの番号を付けて描く）
 *   - 箇条書き
 *   > メモ                 補足の囲み
 *   !! 注意                注意の囲み
 *   [[SCREENSHOT: 説明]]   スクリーンショットの貼り付け枠（説明が枠内に入る）
 *   ---                    ページの区切り（明示したいとき）
 *
 * 本文がページに収まらない場合は自動で次のページに送り、見出しに「（つづき）」を付ける。
 */
'use strict';

const fs = require('fs');
const path = require('path');
const PptxGenJS = require('pptxgenjs');

const ROOT = path.dirname(__dirname);
const DOCS = path.join(ROOT, 'docs');

// A4縦（210mm × 297mm）
const PAGE = { w: 8.27, h: 11.69 };
const MARGIN = 0.62;
const CONTENT_W = PAGE.w - MARGIN * 2;
const BODY_TOP = 1.62;
const BODY_BOTTOM = PAGE.h - 0.72;

/** 役割ごとの色（読み手がひと目で自分のマニュアルだと分かるように変える） */
const THEMES = {
    organizer: { key: '1E2761', soft: 'E8ECF8', name: '主催者向け' },
    company:   { key: '1C7293', soft: 'E3F0F4', name: '企業担当者向け' },
    reception: { key: 'B85042', soft: 'FAEAE7', name: '総合受付向け' },
};

const INK = '222222';
const MUTED = '6B6B6B';
const LINE = 'D8D8D8';

/** 全角はフォントサイズぶん、半角はその約55%の幅として行数を見積もる */
function textHeight(text, fontSize, width, lineHeight = 1.45) {
    const full = fontSize / 72;
    let w = 0;
    let lines = 1;
    for (const ch of String(text)) {
        if (ch === '\n') {
            lines++;
            w = 0;
            continue;
        }
        const cw = /[\x20-\x7E｡-ﾟ]/.test(ch) ? full * 0.55 : full;
        if (w + cw > width) {
            lines++;
            w = cw;
        } else {
            w += cw;
        }
    }
    return lines * (fontSize / 72) * lineHeight;
}

// ---------------------------------------------------------------- 原稿の読み込み

function parseManual(markdown) {
    const lines = markdown.split(/\r?\n/);
    const doc = { title: '', lead: '', pages: [] };
    let page = null;

    const startPage = (heading) => {
        page = { heading, blocks: [] };
        doc.pages.push(page);
    };

    for (const raw of lines) {
        const line = raw.trim();
        if (line === '') continue;

        if (line.startsWith('# ')) {
            doc.title = line.slice(2).trim();
            continue;
        }
        if (line.startsWith('## ')) {
            startPage(line.slice(3).trim());
            continue;
        }
        if (page === null) {
            // 表紙の説明文（最初の見出しより前に書かれた行）
            if (line.startsWith('> ')) doc.lead = line.slice(2).trim();
            continue;
        }
        if (line === '---') {
            startPage(page.heading + '（つづき）');
            continue;
        }
        if (line.startsWith('### ')) {
            page.blocks.push({ type: 'section', text: line.slice(4).trim() });
            continue;
        }
        const screenshot = line.match(/^\[\[SCREENSHOT:\s*(.+?)\]\]$/);
        if (screenshot) {
            page.blocks.push({ type: 'screenshot', text: screenshot[1].trim() });
            continue;
        }
        if (line.startsWith('!! ')) {
            page.blocks.push({ type: 'warn', text: line.slice(3).trim() });
            continue;
        }
        if (line.startsWith('> ')) {
            page.blocks.push({ type: 'note', text: line.slice(2).trim() });
            continue;
        }
        const step = line.match(/^(\d+)\.\s+(.+)$/);
        if (step) {
            page.blocks.push({ type: 'step', number: step[1], text: step[2].trim() });
            continue;
        }
        if (line.startsWith('- ')) {
            page.blocks.push({ type: 'bullet', text: line.slice(2).trim() });
            continue;
        }
        page.blocks.push({ type: 'para', text: line });
    }

    return doc;
}

// ---------------------------------------------------------------- 描画

/**
 * 太字（**…**）とコード（`…`）の記法を pptxgenjs のリッチテキストに変換する。
 * コマンドやファイル名は等幅フォントにして、本文と見分けられるようにする。
 */
function richText(text, base) {
    return text.split(/(\*\*[^*]+\*\*|`[^`]+`)/).filter(Boolean).map((part) => {
        if (part.startsWith('**') && part.endsWith('**')) {
            return { text: part.slice(2, -2), options: { ...base, bold: true } };
        }
        if (part.startsWith('`') && part.endsWith('`')) {
            return {
                text: part.slice(1, -1),
                options: { ...base, fontFace: 'Courier New', fontSize: (base.fontSize || 11) * 0.95 },
            };
        }
        return { text: part, options: { ...base } };
    });
}

function plain(text) {
    return text.replace(/\*\*/g, '').replace(/`/g, '');
}

function blockHeight(block) {
    switch (block.type) {
        case 'section':    return textHeight(plain(block.text), 14, CONTENT_W) + 0.20;
        case 'step':       return Math.max(0.38, textHeight(plain(block.text), 11.5, CONTENT_W - 0.52)) + 0.12;
        case 'bullet':     return textHeight(plain(block.text), 11, CONTENT_W - 0.28) + 0.08;
        case 'para':       return textHeight(plain(block.text), 11, CONTENT_W) + 0.10;
        case 'note':
        case 'warn':       return textHeight(plain(block.text), 10.5, CONTENT_W - 0.55) + 0.34;
        case 'screenshot': return 2.95;
        default:           return 0.2;
    }
}

function drawBlock(slide, block, y, theme) {
    switch (block.type) {
        case 'section':
            slide.addText(richText(block.text, { fontSize: 14, bold: true, color: theme.key, fontFace: 'Calibri' }), {
                x: MARGIN, y: y + 0.12, w: CONTENT_W, h: textHeight(plain(block.text), 14, CONTENT_W),
                margin: 0, isTextBox: true, valign: 'top',
            });
            break;

        case 'step': {
            const h = Math.max(0.34, textHeight(plain(block.text), 11.5, CONTENT_W - 0.52));
            slide.addShape('ellipse', {
                x: MARGIN, y: y + 0.02, w: 0.3, h: 0.3, fill: { color: theme.key },
            });
            slide.addText(block.number, {
                x: MARGIN, y: y + 0.02, w: 0.3, h: 0.3, align: 'center', valign: 'middle',
                fontSize: 11, bold: true, color: 'FFFFFF', margin: 0, isTextBox: true, fontFace: 'Calibri',
            });
            slide.addText(richText(block.text, { fontSize: 11.5, color: INK, fontFace: 'Calibri' }), {
                x: MARGIN + 0.44, y: y, w: CONTENT_W - 0.44, h: h + 0.06,
                margin: 0, isTextBox: true, valign: 'top', lineSpacingMultiple: 1.25,
            });
            break;
        }

        case 'bullet':
            slide.addText(richText(block.text, { fontSize: 11, color: INK, fontFace: 'Calibri' }), {
                x: MARGIN + 0.16, y: y, w: CONTENT_W - 0.16, h: textHeight(plain(block.text), 11, CONTENT_W - 0.28) + 0.06,
                margin: 0, isTextBox: true, valign: 'top', bullet: { characterCode: '25CF' }, lineSpacingMultiple: 1.25,
            });
            break;

        case 'para':
            slide.addText(richText(block.text, { fontSize: 11, color: INK, fontFace: 'Calibri' }), {
                x: MARGIN, y: y, w: CONTENT_W, h: textHeight(plain(block.text), 11, CONTENT_W) + 0.06,
                margin: 0, isTextBox: true, valign: 'top', lineSpacingMultiple: 1.25,
            });
            break;

        case 'note':
        case 'warn': {
            const warn = block.type === 'warn';
            const h = textHeight(plain(block.text), 10.5, CONTENT_W - 0.55) + 0.26;
            slide.addShape('roundRect', {
                x: MARGIN, y: y, w: CONTENT_W, h,
                fill: { color: warn ? 'FDF0E6' : theme.soft }, line: { color: warn ? 'E08A3C' : theme.key, width: 0.75 },
                rectRadius: 0.06,
            });
            slide.addText(warn ? '！' : 'i', {
                x: MARGIN + 0.1, y: y + 0.1, w: 0.26, h: 0.26, align: 'center', valign: 'middle',
                fontSize: 12, bold: true, color: warn ? 'C25A17' : theme.key, margin: 0, isTextBox: true, fontFace: 'Calibri',
            });
            slide.addText(richText(block.text, { fontSize: 10.5, color: INK, fontFace: 'Calibri' }), {
                x: MARGIN + 0.42, y: y + 0.12, w: CONTENT_W - 0.55, h: h - 0.2,
                margin: 0, isTextBox: true, valign: 'top', lineSpacingMultiple: 1.2,
            });
            break;
        }

        case 'screenshot': {
            const h = 2.85;
            slide.addShape('roundRect', {
                x: MARGIN, y: y, w: CONTENT_W, h,
                fill: { color: 'F7F7F7' }, line: { color: LINE, width: 1, dashType: 'dash' }, rectRadius: 0.05,
            });
            slide.addText('［ スクリーンショット ］ ここに貼り付けてください', {
                x: MARGIN + 0.2, y: y + 0.42, w: CONTENT_W - 0.4, h: 0.3,
                align: 'center', fontSize: 11, bold: true, color: MUTED, margin: 0, isTextBox: true, fontFace: 'Calibri',
            });
            slide.addText(block.text, {
                x: MARGIN + 0.3, y: y + 0.82, w: CONTENT_W - 0.6, h: 0.8,
                align: 'center', fontSize: 10.5, color: INK, margin: 0, isTextBox: true,
                valign: 'top', fontFace: 'Calibri', lineSpacingMultiple: 1.2,
            });
            break;
        }
    }
}

function addPage(pres, theme, doc, page, pageNumber) {
    const slide = pres.addSlide();
    slide.background = { color: 'FFFFFF' };

    // 見出し（装飾の線や帯は置かず、余白と文字の大きさで区切る）
    slide.addText(doc.title, {
        x: MARGIN, y: 0.5, w: CONTENT_W, h: 0.26,
        fontSize: 10, color: theme.key, bold: true, margin: 0, isTextBox: true, fontFace: 'Calibri',
    });
    slide.addText(plain(page.heading), {
        x: MARGIN, y: 0.78, w: CONTENT_W, h: 0.7,
        fontSize: 22, bold: true, color: INK, margin: 0, isTextBox: true, valign: 'top', fontFace: 'Calibri',
    });

    let y = BODY_TOP;
    for (const block of page.blocks) {
        const h = blockHeight(block);
        if (y + h > BODY_BOTTOM) {
            break; // 収まらないぶんは呼び出し側が次ページへ回す
        }
        drawBlock(slide, block, y, theme);
        y += h;
    }

    slide.addText(`${theme.name}　${pageNumber}`, {
        x: MARGIN, y: PAGE.h - 0.55, w: CONTENT_W, h: 0.25,
        fontSize: 9, color: MUTED, align: 'right', margin: 0, isTextBox: true, fontFace: 'Calibri',
    });

    return slide;
}

/** 収まらないブロックを次のページへ送りながら描く */
function paginate(page) {
    const pages = [];
    let current = { heading: page.heading, blocks: [] };
    let y = BODY_TOP;

    for (const block of page.blocks) {
        const h = blockHeight(block);
        if (y + h > BODY_BOTTOM && current.blocks.length > 0) {
            pages.push(current);
            current = { heading: page.heading + '（つづき）', blocks: [] };
            y = BODY_TOP;
        }
        current.blocks.push(block);
        y += h;
    }
    if (current.blocks.length > 0) pages.push(current);

    return pages;
}

function buildDeck(sourceFile, themeKey) {
    const theme = THEMES[themeKey];
    const doc = parseManual(fs.readFileSync(sourceFile, 'utf8'));

    const pres = new PptxGenJS();
    pres.defineLayout({ name: 'A4P', width: PAGE.w, height: PAGE.h });
    pres.layout = 'A4P';
    pres.title = doc.title;
    pres.author = '企業周遊アンケートシステム';

    // 表紙
    const cover = pres.addSlide();
    cover.background = { color: theme.key };
    cover.addText('企業周遊アンケートシステム', {
        x: MARGIN, y: 3.6, w: CONTENT_W, h: 0.4,
        fontSize: 13, color: 'FFFFFF', margin: 0, isTextBox: true, fontFace: 'Calibri',
    });
    cover.addText(doc.title, {
        x: MARGIN, y: 4.05, w: CONTENT_W, h: 1.6,
        fontSize: 34, bold: true, color: 'FFFFFF', margin: 0, isTextBox: true, valign: 'top', fontFace: 'Calibri',
    });
    if (doc.lead) {
        cover.addText(doc.lead, {
            x: MARGIN, y: 5.75, w: CONTENT_W, h: 1.2,
            fontSize: 12, color: 'FFFFFF', margin: 0, isTextBox: true, valign: 'top',
            lineSpacingMultiple: 1.3, fontFace: 'Calibri',
        });
    }
    cover.addText('A4縦で印刷できます。スクリーンショットの枠は、実際の画面を撮って貼り替えてください。', {
        x: MARGIN, y: PAGE.h - 1.1, w: CONTENT_W, h: 0.5,
        fontSize: 9.5, color: 'FFFFFF', margin: 0, isTextBox: true, fontFace: 'Calibri',
    });

    let pageNumber = 1;
    let shots = 0;
    for (const page of doc.pages) {
        for (const chunk of paginate(page)) {
            pageNumber++;
            addPage(pres, theme, doc, chunk, pageNumber);
            shots += chunk.blocks.filter((b) => b.type === 'screenshot').length;
        }
    }

    const outFile = sourceFile.replace(/\.md$/, '.pptx');
    return pres.writeFile({ fileName: outFile }).then(() => {
        console.log(`${path.basename(outFile)}  ページ ${pageNumber} / スクショ枠 ${shots}`);
        return { title: doc.title, file: path.basename(outFile), shots: collectShots(doc) };
    });
}

/**
 * スクリーンショット枠の一覧（何ページ目に、どの画面を貼るか）。
 * 撮影する人が上から順に撮っていけるよう、ページ番号つきで書き出す。
 */
function collectShots(doc) {
    const list = [];
    let pageNumber = 1;
    for (const page of doc.pages) {
        for (const chunk of paginate(page)) {
            pageNumber++;
            for (const block of chunk.blocks) {
                if (block.type === 'screenshot') {
                    list.push({ page: pageNumber, heading: plain(chunk.heading), text: block.text });
                }
            }
        }
    }
    return list;
}

function writeShotList(decks) {
    const lines = [
        '# スクリーンショット貼り付け一覧',
        '',
        'マニュアル（`docs/manual-*.pptx`）にある点線の枠に貼る画面の一覧です。',
        '本番サイトで撮影して、枠の上に貼り付けてください（枠ごと削除して構いません）。',
        '',
        '撮影のコツ:',
        '',
        '- ブラウザの表示倍率は100%、不要なタブやブックマークバーは隠すと見やすくなります',
        '- スマートフォンの画面は、端末のスクリーンショット機能で撮ってください',
        '- **テスト用のデータで撮影**し、実在の氏名・メールアドレスが写り込まないようにしてください',
        '- 管理画面の名前を変えている場合（`.env` の `ADMIN_TITLE`）は、本番の名前で撮ると実物どおりになります',
        '',
        'この一覧は `node bin/build_manuals.js` を実行すると原稿から作り直されます。',
        '',
    ];

    for (const deck of decks) {
        lines.push(`## ${deck.title}（${deck.file}）`, '');
        lines.push('| ページ | 見出し | 撮る画面 |', '|---|---|---|');
        for (const shot of deck.shots) {
            lines.push(`| ${shot.page} | ${shot.heading} | ${shot.text} |`);
        }
        lines.push('');
    }

    const out = path.join(DOCS, 'screenshot-list.md');
    fs.writeFileSync(out, lines.join('\n'), 'utf8');
    console.log(`screenshot-list.md  合計 ${decks.reduce((n, d) => n + d.shots.length, 0)} 箇所`);
}

const targets = [
    ['manual-organizer.md', 'organizer'],
    ['manual-company.md', 'company'],
    ['manual-reception.md', 'reception'],
];

(async () => {
    const decks = [];
    for (const [file, themeKey] of targets) {
        const full = path.join(DOCS, file);
        if (!fs.existsSync(full)) {
            console.log(`（スキップ）${file} がありません`);
            continue;
        }
        decks.push(await buildDeck(full, themeKey));
    }
    if (decks.length > 0) {
        writeShotList(decks);
    }
})();
