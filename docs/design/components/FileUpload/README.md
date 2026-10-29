# FileUpload

A dashed drop area (nothing has been set down yet) that turns to the tint while a file is over it, with a button to choose files for people who don't drag. Each file then gets a row: uploading with a bar and percentage, uploaded, or an error that says what to do ("HEIC files can't be attached. Use PNG or JPG."), with Try again.

**Props:** `label`, `accept`, `multiple`, `typesLabel`, `maxSizeLabel`, `files` (`[{name, size, status: 'uploading' | 'done' | 'error', progress?, error?}]`), `onFiles(files)`, `onRemove(file)`, `onRetry(file)`, `labels`, `disabled`.

The component shows state; the app does the upload and checks type and size before it starts, so the error comes at once. File names keep their own direction (`dir="auto"`).
