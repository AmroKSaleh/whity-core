# Toast

A short message at the bottom of the screen (the end corner) after something happened. With Undo it stays 10 seconds and a thin bar counts down; hovering or focusing it pauses the count (7.5c). After that, Undo stays on the activity item for the tool's undo window (`UndoBar`). Errors and anything that needs action use `duration: 0` and stay until dismissed. One line, past tense, says what happened ("Sent 12 payment reminders"). Use `ToastRegion` once in the app shell.
