# ConfirmDialog

For actions that can't be undone. The title asks the question with the thing's name, the description says it can't be undone, a short list says exactly what will be lost, and the red button names the action ("Delete workspace", never "OK"). For the biggest actions (deleting a workspace, revoking every agent) the button stays disabled until the person types the name exactly (`confirmText`). Cancel is always there and Escape closes. Anything that can be undone uses Undo instead of a confirm.
