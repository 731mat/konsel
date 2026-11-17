// Editor formátovaného textu (komentáře): <textarea data-rich-editor> se nahradí editorem TipTap.
// Textarea zůstane skrytá ve formuláři a nese HTML – formulář se odesílá jako dřív (i AJAXem).
// Server HTML vždy pročistí (SymfonyHtmlSanitizer), editor jen hlídá, aby vznikaly povolené značky.
// TipTap (richEditorCore.js) se stahuje líně, jen na stránkách s diskusí.

// Soubory ze schránky nebo přetažené myší patří do přílohy komentáře (dropzone), ne do textu.
// Kopie z Wordu nese i obrázek, ale také text/html – ta se vloží jako text.
export function isFilePaste(data) {
	return Boolean(data?.files.length) && !data.types.includes('text/html');
}

export async function initRichEditors(root) {
	const textareas = [...root.querySelectorAll('textarea[data-rich-editor]')].filter((textarea) => !textarea.richEditor);
	if (textareas.length === 0) {
		return;
	}
	const { createEditor } = await import('./richEditorCore.js');
	textareas.filter((textarea) => !textarea.richEditor && textarea.isConnected).forEach(createEditor);
}

export function destroyRichEditors(root) {
	root.querySelectorAll('textarea[data-rich-editor]').forEach((textarea) => {
		textarea.richEditor?.destroy();
		delete textarea.richEditor;
	});
}

// Zaměří editor (nebo obyčejné pole, pokud editor není).
export function focusTextInput(form) {
	const textarea = form.querySelector('textarea');
	if (textarea?.richEditor) {
		textarea.richEditor.commands.focus('end');
	} else {
		textarea?.focus();
	}
}
