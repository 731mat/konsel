import './main.scss';
import * as bootstrap from 'bootstrap';
import naja from 'naja';
import netteForms from 'nette-forms';

window.bootstrap = bootstrap;

naja.formsHandler.netteForms = netteForms;

document.addEventListener('DOMContentLoaded', () => {
	netteForms.initOnLoad();
	naja.initialize();
});
