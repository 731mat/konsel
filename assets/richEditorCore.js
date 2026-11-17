// Jádro editoru (TipTap) – načítá se až na stránce, kde je pole s data-rich-editor.
import { Editor, Extension } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { Placeholder } from '@tiptap/extensions';

// Soubory patří do přílohy komentáře (dropzone), ne do textu – viz isFilePaste v richEditor.js.
const isFilePaste = (data) => Boolean(data?.files.length) && !data.types.includes('text/html');

const tools = [
	{ name: 'bold', icon: 'bi-type-bold', label: 'Tučně (Ctrl+B)', run: (chain) => chain.toggleBold() },
	{ name: 'italic', icon: 'bi-type-italic', label: 'Kurzíva (Ctrl+I)', run: (chain) => chain.toggleItalic() },
	{ name: 'underline', icon: 'bi-type-underline', label: 'Podtržení (Ctrl+U)', run: (chain) => chain.toggleUnderline() },
	{ name: 'strike', icon: 'bi-type-strikethrough', label: 'Přeškrtnutí', run: (chain) => chain.toggleStrike() },
	null,
	{ name: 'bulletList', icon: 'bi-list-ul', label: 'Odrážky', run: (chain) => chain.toggleBulletList() },
	{ name: 'orderedList', icon: 'bi-list-ol', label: 'Číslovaný seznam', run: (chain) => chain.toggleOrderedList() },
	{ name: 'blockquote', icon: 'bi-quote', label: 'Citace', run: (chain) => chain.toggleBlockquote() },
	null,
	{ name: 'link', icon: 'bi-link-45deg', label: 'Odkaz', run: null },
	{ name: 'clear', icon: 'bi-eraser', label: 'Zrušit formátování', run: (chain) => chain.unsetAllMarks().clearNodes() },
];

function normalizeUrl(value) {
	const url = value.trim();
	if (url === '') {
		return '';
	}
	if (/^(https?:|mailto:)/i.test(url)) {
		return url;
	}
	return url.includes('@') && !url.includes('/') ? `mailto:${url}` : `https://${url}`;
}

function editLink(editor) {
	const previous = editor.getAttributes('link').href ?? '';
	const value = window.prompt('Adresa odkazu (prázdné = zrušit odkaz)', previous);
	if (value === null) {
		return;
	}
	const href = normalizeUrl(value);
	const chain = editor.chain().focus().extendMarkRange('link');
	if (href === '') {
		chain.unsetLink().run();
	} else if (editor.state.selection.empty && !editor.isActive('link')) {
		chain.insertContent({ type: 'text', text: value.trim(), marks: [{ type: 'link', attrs: { href } }] }).run();
	} else {
		chain.setLink({ href }).run();
	}
}

export function createEditor(textarea) {
	const form = textarea.closest('form');
	const wrapper = document.createElement('div');
	wrapper.className = 'rich-editor';
	if (textarea.classList.contains('is-invalid')) {
		wrapper.classList.add('is-invalid');
	}

	const toolbar = document.createElement('div');
	toolbar.className = 'rich-editor-toolbar';
	toolbar.setAttribute('role', 'toolbar');
	toolbar.setAttribute('aria-label', 'Formátování textu');

	const buttons = new Map();
	tools.forEach((tool) => {
		if (tool === null) {
			const separator = document.createElement('span');
			separator.className = 'rich-editor-separator';
			toolbar.append(separator);
			return;
		}
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'rich-editor-button';
		button.title = tool.label;
		button.setAttribute('aria-label', tool.label);
		button.innerHTML = `<i class="bi ${tool.icon}" aria-hidden="true"></i>`;
		// mousedown by vzal editoru výběr textu
		button.addEventListener('mousedown', (event) => event.preventDefault());
		button.addEventListener('click', () => {
			if (tool.run === null) {
				editLink(editor);
			} else {
				tool.run(editor.chain().focus()).run();
			}
		});
		buttons.set(tool.name, button);
		toolbar.append(button);
	});

	const content = document.createElement('div');
	wrapper.append(toolbar, content);
	textarea.before(wrapper);
	textarea.hidden = true;

	const submitShortcut = Extension.create({
		name: 'submitShortcut',
		addKeyboardShortcuts: () => ({
			'Mod-Enter': () => {
				form?.requestSubmit(form.querySelector('[type=submit]') ?? undefined);
				return true;
			},
		}),
	});

	const label = textarea.getAttribute('aria-label') ?? form?.querySelector(`label[for="${textarea.id}"]`)?.textContent ?? 'Text';
	const editor = new Editor({
		element: content,
		content: textarea.value,
		extensions: [
			StarterKit.configure({
				heading: false,
				codeBlock: false,
				horizontalRule: false,
				trailingNode: false,
				link: {
					openOnClick: false,
					autolink: true,
					defaultProtocol: 'https',
					protocols: ['mailto'],
					HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' },
				},
			}),
			Placeholder.configure({ placeholder: textarea.getAttribute('placeholder') ?? '' }),
			submitShortcut,
		],
		editorProps: {
			attributes: {
				class: 'rich-editor-content',
				role: 'textbox',
				'aria-multiline': 'true',
				'aria-label': label,
			},
			handlePaste: (view, event) => isFilePaste(event.clipboardData),
			handleDrop: (view, event) => Boolean(event.dataTransfer?.files.length),
		},
		onUpdate: ({ editor: current }) => {
			textarea.value = current.isEmpty ? '' : current.getHTML();
		},
	});

	const refresh = () => {
		buttons.forEach((button, name) => {
			const active = name !== 'clear' && editor.isActive(name);
			button.classList.toggle('active', active);
			button.setAttribute('aria-pressed', String(active));
		});
	};
	editor.on('selectionUpdate', refresh);
	editor.on('transaction', refresh);
	editor.on('focus', () => wrapper.classList.add('focus'));
	editor.on('blur', () => wrapper.classList.remove('focus'));
	refresh();

	textarea.richEditor = editor;
	return editor;
}
