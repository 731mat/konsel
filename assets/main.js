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
		const input = zone.querySelector('[data-dropzone-input]');
		const list = zone.querySelector('[data-dropzone-list]');
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

// Náhled čísla usnesení u masky orgánu: <input data-numbering-mask> + <strong data-numbering-preview>.
// Ukázka = 7. usnesení, 3. zasedání, bod 4, rok 2026 (stejně jako NumberingMask::example()). Platnost hlídá server.
function previewNumbering(input) {
	const preview = input.closest('form')?.querySelector('[data-numbering-preview]');
	if (!preview) {
		return;
	}
	const values = { n: '7', yyyy: '2026', yy: '26', meeting: '3', item: '4', body: 'ZO' };
	const tokens = [...input.value.matchAll(/\{([a-z]+)(?::(\d+))?\}/g)];
	const valid = tokens.filter((t) => t[1] === 'n').length === 1 && tokens.every((t) => t[1] in values && (t[2] === undefined || t[1] === 'n'));
	preview.textContent = valid
		? input.value.replace(/\{([a-z]+)(?::(\d+))?\}/g, (_, name, width) => (width ? values[name].padStart(Number(width), '0') : values[name]))
		: 'maska musí obsahovat {n} právě jednou';
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
