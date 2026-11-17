import './main.scss';
import * as bootstrap from 'bootstrap';
import naja from 'naja';
import netteForms from 'nette-forms';
import Sortable from 'sortablejs';
import { destroyRichEditors, focusTextInput, initRichEditors, isFilePaste } from './richEditor.js';
import { initThemePreview } from './themePreview.js';

window.bootstrap = bootstrap;

naja.formsHandler.netteForms = netteForms;

// Přeskládání seznamu myší: <ol data-sortable-url="…"> s položkami data-id a úchytem .drag-handle.
// Nové pořadí se pošle AJAXem jako order[] a server vrátí překreslený snippet.
function initSortable(root) {
	root.querySelectorAll('[data-sortable-url]').forEach((list) => {
		if (list.dataset.sortableReady) {
			return;
		}
		list.dataset.sortableReady = '1';
		Sortable.create(list, {
			handle: '.drag-handle',
			animation: 150,
			ghostClass: 'sortable-ghost',
			onEnd: (event) => {
				if (event.oldIndex === event.newIndex) {
					return;
				}
				const body = new FormData();
				list.querySelectorAll(':scope > [data-id]').forEach((item) => body.append('order[]', item.dataset.id));
				naja.makeRequest('POST', list.dataset.sortableUrl, body, { history: false });
			},
		});
	});
}

// Přetahování souborů: <div data-dropzone> s <input type=file multiple data-dropzone-input> a seznamem [data-dropzone-list].
// Soubory se přidávají k výběru (přetažením, tlačítkem [data-dropzone-pick] i vložením ze schránky).
function formatSize(bytes) {
	return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024))} kB` : `${(bytes / 1024 / 1024).toFixed(1).replace('.', ',')} MB`;
}

function initDropzones(root) {
	root.querySelectorAll('[data-dropzone]').forEach((zone) => {
		if (zone.dataset.dropzoneReady) {
			return;
		}
		zone.dataset.dropzoneReady = '1';
		// formulář může být v samostatném okně (data-dropzone-modal), zónou je pak celá karta
		const modal = zone.dataset.dropzoneModal ? document.querySelector(zone.dataset.dropzoneModal) : null;
		const scope = modal ?? zone;
		const input = scope.querySelector('[data-dropzone-input]');
		const list = scope.querySelector('[data-dropzone-list]');
		const setFiles = (files) => {
			const transfer = new DataTransfer();
			files.forEach((file) => transfer.items.add(file));
			input.files = transfer.files;
			list.replaceChildren(...files.map((file, index) => {
				const item = document.createElement('li');
				item.className = 'd-flex align-items-center gap-2';
				item.innerHTML = '<i class="bi bi-file-earmark" aria-hidden="true"></i><span class="text-truncate"></span><span class="text-body-secondary"></span><button type="button" class="btn btn-link btn-sm p-0 text-danger ms-auto" aria-label="Odebrat soubor"><i class="bi bi-x-lg"></i></button>';
				item.querySelector('.text-truncate').textContent = file.name;
				item.querySelector('.text-body-secondary').textContent = formatSize(file.size);
				item.querySelector('button').addEventListener('click', () => setFiles(files.filter((_, i) => i !== index)));
				return item;
			}));
			list.hidden = files.length === 0;
		};
		const addFiles = (files) => setFiles([...input.files, ...files]);

		zone.addEventListener('dragover', (event) => {
			if (event.dataTransfer?.types.includes('Files')) {
				event.preventDefault();
				zone.classList.add('dropzone-active');
			}
		});
		zone.addEventListener('dragleave', (event) => {
			if (!zone.contains(event.relatedTarget)) {
				zone.classList.remove('dropzone-active');
			}
		});
		zone.addEventListener('drop', (event) => {
			zone.classList.remove('dropzone-active');
			if (event.dataTransfer?.files.length) {
				event.preventDefault();
				addFiles([...event.dataTransfer.files]);
				// zóna s formulářem v okně (např. přílohy bodu): po puštění souborů okno otevřít k potvrzení
				if (modal) {
					bootstrap.Modal.getOrCreateInstance(modal).show();
				}
			}
		});
		zone.addEventListener('paste', (event) => {
			if (isFilePaste(event.clipboardData)) {
				event.preventDefault();
				addFiles([...event.clipboardData.files]);
			}
		});
		input.addEventListener('change', () => setFiles([...input.files]));
		zone.closest('form')?.querySelector('[data-dropzone-pick]')?.addEventListener('click', () => {
			const picker = document.createElement('input');
			picker.type = 'file';
			picker.multiple = true;
			picker.accept = input.accept;
			picker.addEventListener('change', () => addFiles([...picker.files]));
			picker.click();
		});
	});
}

// okna patří přímo do <body> (jinak je vrstvení stránky schová pod tmavé pozadí);
// okno s chybou formuláře po odeslání zůstane otevřené
document.addEventListener('DOMContentLoaded', () => {
	document.querySelectorAll('.modal').forEach((modal) => {
		if (modal.parentElement !== document.body) {
			document.body.append(modal);
		}
	});
	document.querySelectorAll('.modal[data-show-on-load]').forEach((modal) => bootstrap.Modal.getOrCreateInstance(modal).show());
});

document.addEventListener('DOMContentLoaded', () => {
	netteForms.initOnLoad();
	naja.initialize();
	initSortable(document);
	initDropzones(document);
	initRichEditors(document);
	initThemePreview(document);
});

naja.snippetHandler.addEventListener('beforeUpdate', (event) => {
	destroyRichEditors(event.detail.snippet);
});

naja.snippetHandler.addEventListener('afterUpdate', (event) => {
	initSortable(event.detail.snippet);
	initDropzones(event.detail.snippet);
	initRichEditors(event.detail.snippet);
});

// Potvrzení nevratných akcí: <button data-confirm="Opravdu smazat?">
document.addEventListener('click', (event) => {
	const target = event.target instanceof Element ? event.target.closest('[data-confirm]') : null;
	if (target && !window.confirm(target.getAttribute('data-confirm') ?? 'Opravdu?')) {
		event.preventDefault();
		event.stopImmediatePropagation();
	}
}, true);

// Odpověď v diskusi: <button data-reply-to="ID" data-reply-name="Jméno"> nastaví parentId ve formuláři diskuse.
document.addEventListener('click', (event) => {
	const target = event.target instanceof Element ? event.target : null;
	const replyButton = target?.closest('[data-reply-to]');
	const cancelButton = target?.closest('[data-reply-cancel]');
	const form = (replyButton ?? cancelButton)?.closest('.card')?.querySelector('form.comment-form');
	if (!form) {
		return;
	}

	const info = form.querySelector('[data-reply-info]');
	form.querySelector('input[name=parentId]').value = replyButton ? replyButton.dataset.replyTo : '';
	info.hidden = !replyButton;
	if (replyButton) {
		info.querySelector('[data-reply-name]').textContent = replyButton.dataset.replyName;
		focusTextInput(form);
	}
});

// Kopírování do schránky: <button data-copy="#idPole">
document.addEventListener('click', async (event) => {
	const button = event.target instanceof Element ? event.target.closest('[data-copy]') : null;
	const input = button ? document.querySelector(button.dataset.copy) : null;
	if (!input) {
		return;
	}

	input.select();
	try {
		await navigator.clipboard.writeText(input.value);
	} catch {
		document.execCommand('copy');
	}
	const label = button.innerHTML;
	button.innerHTML = '<i class="bi bi-check2" aria-hidden="true"></i> Zkopírováno';
	setTimeout(() => { button.innerHTML = label; }, 2000);
});

// Šablony usnesení v modálním okně: hledání a vložení do formuláře bodu
document.addEventListener('input', (event) => {
	const search = event.target instanceof Element ? event.target.closest('[data-template-search]') : null;
	if (!search) {
		return;
	}
	const query = search.value.trim().toLocaleLowerCase('cs');
	search.closest('.modal').querySelectorAll('[data-template-insert]').forEach((item) => {
		item.hidden = query !== '' && !item.dataset.search.toLocaleLowerCase('cs').includes(query);
	});
});

document.addEventListener('click', (event) => {
	const item = event.target instanceof Element ? event.target.closest('[data-template-insert]') : null;
	if (!item) {
		return;
	}
	const form = document.querySelector('form[id^="frm-itemForm"]');
	const resolution = form?.querySelector('textarea[name=proposedResolution]');
	const title = form?.querySelector('input[name=title]');
	if (!resolution) {
		return;
	}
	if (resolution.value.trim() !== '' && !window.confirm('Nahradit dosavadní návrh usnesení textem ze šablony?')) {
		return;
	}
	resolution.value = item.dataset.text;
	if (title && title.value.trim() === '') {
		title.value = item.dataset.title;
	}
	window.bootstrap.Modal.getInstance(item.closest('.modal'))?.hide();
	resolution.focus();
});

// Průběžné ukládání textu: <form data-autosave> s textarea – uloží se při opuštění pole (i bez kliknutí na Uložit).
// Požadavek nesmí zrušit souběžné AJAXové kliknutí (hlasování), proto unique: false a bez překreslení snippetů.
function autosave(form) {
	const status = form.querySelector('[data-autosave-status]');
	const textarea = form.querySelector('textarea');
	if (textarea.dataset.saved === textarea.value) {
		return;
	}
	const value = textarea.value;
	status.textContent = 'Ukládám…';
	naja.makeRequest('POST', form.action, new FormData(form), { unique: false, history: false })
		.then((payload) => {
			if (payload?.error) {
				status.textContent = payload.error;
				status.classList.add('text-danger');
				return;
			}
			textarea.dataset.saved = value;
			status.classList.remove('text-danger');
			status.textContent = `Uloženo v ${new Date().toLocaleTimeString('cs-CZ', { hour: 'numeric', minute: '2-digit' })}`;
		})
		.catch(() => {
			status.textContent = 'Nepodařilo se uložit – zkuste Uložit.';
			status.classList.add('text-danger');
		});
}

document.addEventListener('focusin', (event) => {
	const textarea = event.target instanceof HTMLTextAreaElement ? event.target : null;
	if (textarea?.closest('form[data-autosave]') && textarea.dataset.saved === undefined) {
		textarea.dataset.saved = textarea.value;
	}
});

document.addEventListener('change', (event) => {
	const form = event.target instanceof HTMLTextAreaElement ? event.target.closest('form[data-autosave]') : null;
	if (form) {
		autosave(form);
	}
});

document.addEventListener('submit', (event) => {
	const form = event.target instanceof HTMLFormElement && event.target.matches('form[data-autosave]') ? event.target : null;
	if (form) {
		event.preventDefault();
		const textarea = form.querySelector('textarea');
		textarea.dataset.saved ??= '\u0000';
		autosave(form);
	}
});

// editor šablon: Tab vloží tabulátor místo přechodu na další pole
document.addEventListener('keydown', (event) => {
	const field = event.target;
	if (event.key !== 'Tab' || event.shiftKey || !(field instanceof HTMLTextAreaElement) || !field.classList.contains('code-editor')) {
		return;
	}

	event.preventDefault();
	field.setRangeText('\t', field.selectionStart, field.selectionEnd, 'end');
});

// filtr, který se odešle hned po výběru (bez inline JS kvůli budoucí CSP)
document.addEventListener('change', (event) => {
	const field = event.target;
	if (field instanceof HTMLSelectElement && field.matches('[data-autosubmit]') && field.form) {
		field.form.requestSubmit();
	}
});

// Náhled čísla usnesení u masky orgánu: <input data-numbering-mask> + <strong data-numbering-preview>.
// Ukázka = 7. usnesení, 3. zasedání, bod 4, rok 2026 (stejně jako NumberingMask::example()). Platnost hlídá server.
// S {sub} ukáže číslo s podbodem 2 i bez podbodů; část v [ ] se vypíše jen s podbodem.
function previewNumbering(input) {
	const preview = input.closest('form')?.querySelector('[data-numbering-preview]');
	if (!preview) {
		return;
	}
	const values = { n: '7', yyyy: '2026', yy: '26', meeting: '3', item: '4', body: 'ZO' };
	const tokens = [...input.value.matchAll(/\{([a-z]+)(?::(\d+))?\}/g)];
	const valid = tokens.filter((t) => t[1] === 'n').length === 1
		&& tokens.every((t) => (t[1] in values || t[1] === 'sub') && (t[2] === undefined || t[1] === 'n'))
		&& !/[[\]]/.test(input.value.replace(/\[[^[\]]*\]/g, ''));
	const render = (sub) => input.value
		.replace(/\[([^[\]]*)\]/g, (_, inner) => (inner.includes('{sub}') && sub === null ? '' : inner))
		.replace(/\{([a-z]+)(?::(\d+))?\}/g, (_, name, width) => {
			if (name === 'sub') {
				return sub ?? '';
			}
			return width ? values[name].padStart(Number(width), '0') : values[name];
		});
	preview.textContent = !valid
		? 'maska musí obsahovat {n} právě jednou (a spárované [ ])'
		: (input.value.includes('{sub}') ? `${render('2')} (bez podbodů ${render(null)})` : render(null));
	preview.classList.toggle('text-danger', !valid);
}

document.addEventListener('input', (event) => {
	if (event.target instanceof HTMLInputElement && event.target.matches('[data-numbering-mask]')) {
		previewNumbering(event.target);
	}
});

document.addEventListener('DOMContentLoaded', () => {
	document.querySelectorAll('[data-numbering-mask]').forEach(previewNumbering);
});

// Zámky diskuse na obrazovce průběhu (ADR A14): při psaní textarea[data-lock-url] drží zámek bodu
// (obnova každých 20 s), po opuštění pole a při odchodu ze stránky ho uvolní. Cizí zámek = pole jen ke čtení.
let heldLock = null;

function lockRequest(url) {
	return naja.makeRequest('POST', url, null, { unique: false, history: false });
}

function releaseLock() {
	if (!heldLock) {
		return;
	}
	clearInterval(heldLock.timer);
	const url = heldLock.textarea.dataset.unlockUrl;
	heldLock = null;
	if (navigator.sendBeacon) {
		navigator.sendBeacon(url);
	} else {
		lockRequest(url).catch(() => {});
	}
}

document.addEventListener('focusin', (event) => {
	const textarea = event.target instanceof HTMLTextAreaElement && event.target.dataset.lockUrl ? event.target : null;
	if (!textarea || textarea.readOnly || heldLock?.textarea === textarea) {
		return;
	}
	releaseLock();
	lockRequest(textarea.dataset.lockUrl).then((payload) => {
		if (payload?.lockedBy) {
			textarea.readOnly = true;
			textarea.blur();
			const status = textarea.closest('form')?.querySelector('[data-autosave-status]');
			if (status) {
				status.textContent = `Bod právě upravuje ${payload.lockedBy}.`;
			}
			return;
		}
		if (document.activeElement === textarea) {
			heldLock = { textarea, timer: setInterval(() => lockRequest(textarea.dataset.lockUrl).catch(() => {}), 20000) };
		} else {
			lockRequest(textarea.dataset.unlockUrl).catch(() => {});
		}
	}).catch(() => {});
});

document.addEventListener('focusout', (event) => {
	if (heldLock && event.target === heldLock.textarea) {
		// change (průběžné uložení) proběhne před focusout – zámek se uvolní až po odeslání textu
		setTimeout(releaseLock, 0);
	}
});

window.addEventListener('pagehide', releaseLock);

// Stav zámků a uložený text po překreslení snippetů: <span data-lock-for="idTextarea" data-lock-holder data-discussion>.
function applyLocks(root) {
	root.querySelectorAll?.('[data-lock-for]').forEach((info) => {
		const textarea = document.getElementById(info.dataset.lockFor);
		if (!textarea || textarea === document.activeElement) {
			return;
		}
		textarea.readOnly = (info.dataset.lockHolder ?? '') !== '';
		// cizí uložený text převezmeme, jen když tu nejsou neuložené změny
		const saved = textarea.dataset.saved ?? textarea.defaultValue;
		const latest = info.dataset.discussion ?? '';
		if (textarea.value === saved && latest !== saved) {
			textarea.value = latest;
			textarea.dataset.saved = latest;
		}
	});
}

naja.snippetHandler.addEventListener('afterUpdate', (event) => applyLocks(event.detail.snippet));

// Průběžné obnovení obrazovky průběhu (jen viditelná karta): <element data-poll-url>.
document.addEventListener('DOMContentLoaded', () => {
	const poller = document.querySelector('[data-poll-url]');
	if (!poller) {
		return;
	}
	setInterval(() => {
		if (document.visibilityState === 'visible' && !document.querySelector('.modal.show')) {
			naja.makeRequest('GET', poller.dataset.pollUrl, null, { unique: 'poll', history: false }).catch(() => {});
		}
	}, 5000);
});

// Diagramy ve wiki (Nápověda): bloky ```mermaid z Markdownu vykreslí Mermaid – knihovna se stáhne jen tady.
async function renderDiagrams(root) {
	const blocks = [...root.querySelectorAll('pre > code.language-mermaid')];
	if (blocks.length === 0) {
		return;
	}
	const { default: mermaid } = await import('mermaid');
	mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: document.documentElement.dataset.bsTheme === 'dark' ? 'dark' : 'default' });
	const nodes = blocks.map((code) => {
		const diagram = document.createElement('div');
		diagram.className = 'mermaid wiki-diagram';
		diagram.textContent = code.textContent;
		code.parentElement.replaceWith(diagram);
		return diagram;
	});
	await mermaid.run({ nodes });
}

document.addEventListener('DOMContentLoaded', () => renderDiagrams(document));
