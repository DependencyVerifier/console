import { startStimulusApp } from '@symfony/reprise/stimulus';

const app = startStimulusApp();
// Keep the browser console quiet (set to true locally to trace controller lifecycles).
app.debug = false;
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);
