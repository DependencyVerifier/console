import { startStimulusApp } from '@symfony/reprise/stimulus';

// Registers the controllers of assets/controllers.json (Symfony UX) and assets/controllers/*_controller.ts.
const app = startStimulusApp();
// Keep the browser console quiet (set to true locally to trace controller lifecycles).
app.debug = false;
// Register third-party controllers here: app.register('some-name', SomeImportedController);
