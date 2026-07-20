# whity_tokens

Generated Whity design tokens (colors, typography, spacing, radius) for
Flutter / Material Design 3, mirroring the same `src/design/tokens/base.json`
source of truth used by `@amroksaleh/ui` and `@amroksaleh/tokens` on the web
side.

Not published to pub.dev — consume it as a git dependency, pinned to a tag or
commit so updates are deliberate:

```yaml
dependencies:
  whity_tokens:
    git:
      url: https://github.com/AmroKSaleh/whity-core.git
      path: flutter/whity_tokens
      ref: main # prefer a tag (e.g. a future tokens-vN release tag) or commit SHA for reproducibility
```

```dart
import 'package:whity_tokens/whity_tokens.dart';

final color = AppTokens.colors(isDarkMode)['primary'];
```

`lib/src/generated/tokens.dart` is generated — do not hand-edit it. It's
regenerated (along with every other token target) by running
`npm run tokens:generate` from `web/` in the whity-core repo.
