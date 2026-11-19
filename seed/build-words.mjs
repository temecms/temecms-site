// Writes seed/words.json: the passages the `words` seed step rewrites on a site that already holds an older seed.
// Each is a block of an item as the older seed wrote it (its plain text) beside the block the seed writes now.
// Run once per rewrite, with the older seed's content.json:  node seed/build-words.mjs path/to/older/content.json
import fs from 'node:fs';
import path from 'node:path';

/** The text of a block as the `words` step reads it: leaf text joined, items and rows apart by a space. Keep in step with temecms_site_plain_text(). */
export const plain = (n) => {
    if (n.type === 'text') { return n.text; }
    if (n.attrs && typeof n.attrs.text === 'string') { return n.attrs.text; }
    const children = n.content ?? [];
    return children.every((c) => c.type === 'text') ? children.map(plain).join('') : children.map(plain).join(' ');
};

// Items whose sign-off the step also fixes: the person they should credit, when they credit only their owner's account.
const BYLINES = { 'post:release-0-7-0-alpha-1': 'The Teme team' };

if (process.argv[1] === import.meta.filename) {
    const dir = import.meta.dirname;
    const older = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
    const current = JSON.parse(fs.readFileSync(path.join(dir, 'content.json'), 'utf8'));
    const index = (c) => {
        const map = {};
        for (const bucket of ['pages', 'posts', 'docs', 'plugins', 'themes']) {
            for (const it of c[bucket] ?? []) { map[`${it.type}:${it.key}`] = { type: it.type, slug: it.slug, fields: it.fields ?? {}, blocks: it.body.content }; }
        }
        for (const [name, body] of Object.entries(c.regions)) { map[`region:${name}`] = { type: 'region', slug: `temecms--${name}`, fields: {}, blocks: body.content }; }
        return map;
    };
    const was = index(older);
    const now = index(current);
    const items = [];
    for (const [ref, before] of Object.entries(was)) {
        const after = now[ref];
        if (after === undefined) { continue; }
        const oldText = before.blocks.map(plain);
        const newText = after.blocks.map(plain);
        const gone = [];
        const added = [];
        before.blocks.forEach((block, i) => { if (!newText.includes(oldText[i])) { gone.push(i); } });
        after.blocks.forEach((block, i) => { if (!oldText.includes(newText[i])) { added.push(i); } });
        if (gone.length !== added.length) { throw new Error(`${ref}: ${gone.length} blocks changed to ${added.length}; pair them by hand`); }
        const replace = gone.map((g, k) => ({ old: oldText[g], new: after.blocks[added[k]] }));
        const fields = {};
        for (const [handle, value] of Object.entries(before.fields)) {
            if (handle in after.fields && JSON.stringify(after.fields[handle]) !== JSON.stringify(value)) { fields[handle] = [value, after.fields[handle]]; }
        }
        const entry = { ref, type: after.type, slug: after.slug, replace, ...(Object.keys(fields).length ? { fields } : {}), ...(BYLINES[ref] ? { byline: BYLINES[ref] } : {}) };
        if (replace.length || entry.fields || entry.byline) { items.push(entry); }
    }
    fs.writeFileSync(path.join(dir, 'words.json'), JSON.stringify({ items }, null, 1));
    console.log(`items:${items.length} passages:${items.reduce((n, it) => n + it.replace.length, 0)}`);
}
