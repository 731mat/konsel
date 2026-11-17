// Živý náhled barev obce v Nastavení → Vzhled. Odvozené odstíny počítá stejně jako App\UI\Theme\BrandStyle.

const hexToRgb = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
const rgbToHex = (rgb) => `#${rgb.map((c) => Math.round(Math.max(0, Math.min(255, c))).toString(16).padStart(2, '0')).join('')}`;
const mix = (hex, other, weight) => {
	const a = hexToRgb(hex);
	const b = hexToRgb(other);
	return rgbToHex(a.map((c, i) => c + (b[i] - c) * weight));
};
const tint = (hex, weight) => mix(hex, '#ffffff', weight);
const shade = (hex, weight) => mix(hex, '#000000', weight);
const luminance = (hex) => {
	const [r, g, b] = hexToRgb(hex).map((c) => {
		const v = c / 255;
		return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
	});
	return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const contrast = (a, b) => {
	const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
	return (hi + 0.05) / (lo + 0.05);
};
const rgb = (hex) => hexToRgb(hex).join(', ');

function readableOn(color, background, dark) {
	let candidate = dark ? tint(color, 0.2) : shade(color, 0.4);
	for (let step = 0; step < 10 && contrast(candidate, background) < 4.5; step++) {
		candidate = dark ? tint(candidate, 0.2) : shade(candidate, 0.2);
	}
	return candidate;
}

function variables(primary, accent, dark) {
	const onPrimary = contrast(primary, '#ffffff') >= contrast(primary, '#111827') ? '#ffffff' : '#111827';
	const lightText = onPrimary === '#ffffff';
	const link = dark ? tint(primary, 0.4) : primary;
	const accentBg = dark ? shade(accent, 0.75) : tint(accent, 0.8);
	return {
		'--bs-primary': primary,
		'--bs-primary-rgb': rgb(primary),
		'--bs-primary-text-emphasis': dark ? tint(primary, 0.4) : shade(primary, 0.6),
		'--bs-primary-bg-subtle': dark ? shade(primary, 0.8) : tint(primary, 0.8),
		'--bs-primary-border-subtle': dark ? shade(primary, 0.4) : tint(primary, 0.6),
		'--bs-link-color': link,
		'--bs-link-color-rgb': rgb(link),
		'--bs-focus-ring-color': `rgba(${rgb(primary)}, .25)`,
		'--konsel-on-primary': onPrimary,
		'--konsel-primary-hover': lightText ? shade(primary, 0.15) : tint(primary, 0.15),
		'--konsel-primary-active': lightText ? shade(primary, 0.2) : tint(primary, 0.2),
		'--konsel-accent': accent,
		'--konsel-accent-rgb': rgb(accent),
		'--konsel-accent-text': readableOn(accent, accentBg, dark),
		'--konsel-accent-bg-subtle': accentBg,
	};
}

export function initThemePreview(root) {
	const preview = root.querySelector('[data-theme-preview]');
	const form = preview ? document.querySelector('[data-theme-input]')?.closest('form') : null;
	if (!preview || !form || preview.dataset.themePreviewReady) {
		return;
	}
	preview.dataset.themePreviewReady = '1';

	const inputs = {
		primary: form.querySelector('[data-theme-input="primary"]'),
		accent: form.querySelector('[data-theme-input="accent"]'),
	};
	const warning = document.querySelector('[data-theme-contrast]');
	const minContrast = Number(preview.dataset.minContrast || 3);

	const update = () => {
		const primary = inputs.primary.value.toLowerCase();
		const accent = inputs.accent.value.toLowerCase();
		const dark = document.documentElement.dataset.bsTheme === 'dark';
		Object.entries(variables(primary, accent, dark)).forEach(([name, value]) => preview.style.setProperty(name, value));
		form.querySelectorAll('[data-theme-value]').forEach((label) => {
			label.textContent = inputs[label.dataset.themeValue].value.toLowerCase();
		});
		form.querySelectorAll('[data-theme-preset]').forEach((button) => {
			const active = button.dataset.primary === primary && button.dataset.accent === accent;
			button.classList.toggle('active', active);
			button.setAttribute('aria-pressed', String(active));
		});
		if (warning) {
			warning.hidden = contrast(primary, '#ffffff') >= minContrast;
		}
	};

	Object.values(inputs).forEach((input) => input.addEventListener('input', update));
	form.querySelectorAll('[data-theme-preset]').forEach((button) => {
		button.addEventListener('click', () => {
			inputs.primary.value = button.dataset.primary;
			inputs.accent.value = button.dataset.accent;
			update();
		});
	});
	update();
}
