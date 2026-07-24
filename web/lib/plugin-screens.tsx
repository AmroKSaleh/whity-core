/**
 * App-owned plugin screen registrations (WC-169).
 *
 * This is the single app-level file the plugin UI registry expects (see
 * `lib/plugin-ui-registry.tsx`): it wires plugin feature ids to the bespoke
 * screen components this app provides. It is imported once for its side effects
 * from the root `app/layout.tsx` (`import '@/lib/plugin-screens';`), so every
 * registration runs before any feature screen renders.
 *
 * To add a bespoke override, add one import and one `registerPluginScreen(...)`
 * call below — nothing else belongs in this file.
 */

import { registerPluginScreen } from '@/lib/plugin-ui-registry';
import { HelloGreetingsScreen } from '@/components/hello/greetings-screen';
import { DemoCatalogScreen } from '@/components/demo-catalog/demo-catalog-screen';

registerPluginScreen('hello-greetings', HelloGreetingsScreen);
registerPluginScreen('demo-catalog', DemoCatalogScreen);
