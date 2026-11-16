import './main.scss';
import * as bootstrap from 'bootstrap';
import naja from 'naja';
import netteForms from 'nette-forms';
import Sortable from 'sortablejs';

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

document.addEventListener('DOMContentLoaded', () => {
	netteForms.initOnLoad();
	naja.initialize();
	initSortable(document);
});

naja.snippetHandler.addEventListener('afterUpdate', (event) => initSortable(event.detail.snippet));

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
		form.querySelector('textarea').focus();
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
