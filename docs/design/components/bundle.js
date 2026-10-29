/* @ds-bundle: {"format":4,"namespace":"Whity","components":[{"name":"Mark"},{"name":"Lockup"},{"name":"BuiltOnWhity"},{"name":"Icon"},{"name":"Blueprint"},{"name":"Button"},{"name":"IconButton"},{"name":"Badge"},{"name":"Card"},{"name":"Input"},{"name":"Select"},{"name":"Switch"},{"name":"Tabs"},{"name":"Alert"},{"name":"EmptyState"},{"name":"PageHeader"},{"name":"Breadcrumb"},{"name":"Skeleton"},{"name":"DataTable"},{"name":"Dialog"},{"name":"AppShell"},{"name":"Money"},{"name":"PluginCard"},{"name":"ActorChip"},{"name":"ActionProposal"},{"name":"AgentActivity"},{"name":"ScopeGrant"},{"name":"Doc"},{"name":"DocSign"},{"name":"DocNote"},{"name":"DocAmount"},{"name":"DocTable"},{"name":"DocPages"},{"name":"DocKpis"},{"name":"DocBars"},{"name":"TenantLogo"},{"name":"Tooltip"},{"name":"Textarea"},{"name":"Combobox"},{"name":"RadioGroup"},{"name":"DatePicker"},{"name":"MoneyInput"},{"name":"OtpInput"},{"name":"SearchField"},{"name":"TenantSwitcher"},{"name":"Segmented"},{"name":"LanguageSwitch"},{"name":"ThemeSwitch"},{"name":"Avatar"},{"name":"DescriptionList"},{"name":"Timeline"},{"name":"Toast"},{"name":"ToastRegion"},{"name":"ConfirmDialog"},{"name":"Drawer"},{"name":"Menu"},{"name":"Popover"},{"name":"ApprovalQueue"},{"name":"UndoBar"},{"name":"ButtonGroup"},{"name":"SplitButton"},{"name":"ToggleGroup"},{"name":"PhoneInput"},{"name":"FileUpload"},{"name":"CommandPalette"},{"name":"Stepper"},{"name":"AgentRunStatus"},{"name":"PlanGate"},{"name":"BarChart"},{"name":"LineChart"},{"name":"DonutChart"},{"name":"DocPreview"}]} */
(function () {
  var React = window.React;
  var h = React.createElement;
  var useState = React.useState;
  var ICONS = {"dashboard": "<path d=\"M5 4h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1\" /> <path d=\"M5 16h4a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1\" /> <path d=\"M15 12h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-6a1 1 0 0 1 1 -1\" /> <path d=\"M15 4h4a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1v-2a1 1 0 0 1 1 -1\" />", "tenant": "<path d=\"M3 21l18 0\" /> <path d=\"M9 8l1 0\" /> <path d=\"M9 12l1 0\" /> <path d=\"M9 16l1 0\" /> <path d=\"M14 8l1 0\" /> <path d=\"M14 12l1 0\" /> <path d=\"M14 16l1 0\" /> <path d=\"M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16\" />", "users": "<path d=\"M5 7a4 4 0 1 0 8 0a4 4 0 1 0 -8 0\" /> <path d=\"M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2\" /> <path d=\"M16 3.13a4 4 0 0 1 0 7.75\" /> <path d=\"M21 21v-2a4 4 0 0 0 -3 -3.85\" />", "user": "<path d=\"M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0\" /> <path d=\"M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2\" />", "role": "<path d=\"M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3\" /> <path d=\"M11 11a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" /> <path d=\"M12 12l0 2.5\" />", "permission": "<path d=\"M16.555 3.843l3.602 3.602a2.877 2.877 0 0 1 0 4.069l-2.643 2.643a2.877 2.877 0 0 1 -4.069 0l-.301 -.301l-6.558 6.558a2 2 0 0 1 -1.239 .578l-.175 .008h-1.172a1 1 0 0 1 -.993 -.883l-.007 -.117v-1.172a2 2 0 0 1 .467 -1.284l.119 -.13l.414 -.414h2v-2h2v-2l2.144 -2.144l-.301 -.301a2.877 2.877 0 0 1 0 -4.069l2.643 -2.643a2.877 2.877 0 0 1 4.069 0\" /> <path d=\"M15 9h.01\" />", "delegation": "<path d=\"M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0\" /> <path d=\"M6 21v-2a4 4 0 0 1 4 -4h3\" /> <path d=\"M16 22l5 -5\" /> <path d=\"M21 21.5v-4.5h-4.5\" />", "ai-principal": "<path stroke=\"none\" d=\"M0 0h24v24H0z\" fill=\"none\" />\n  <path d=\"M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2\" />\n  <path d=\"M9 16c1 .667 2 1 3 1s2 -.333 3 -1\" />\n  <path d=\"M9 7l-1 -4\" />\n  <path d=\"M15 7l1 -4\" />\n  <path d=\"M9 12v-1\" />\n  <path d=\"M15 12v-1\" />", "approval": "<path d=\"M21 17.85h-18c0 -4.05 1.421 -4.05 3.79 -4.05c5.21 0 1.21 -4.59 1.21 -6.8a4 4 0 1 1 8 0c0 2.21 -4 6.8 1.21 6.8c2.369 0 3.79 0 3.79 4.05\" /> <path d=\"M5 21h14\" />", "scope": "<path d=\"M4 8v-2a2 2 0 0 1 2 -2h2\" /> <path d=\"M4 16v2a2 2 0 0 0 2 2h2\" /> <path d=\"M16 4h2a2 2 0 0 1 2 2v2\" /> <path d=\"M16 20h2a2 2 0 0 0 2 -2v-2\" /> <path d=\"M8 12a1 1 0 0 1 1 -1h6a1 1 0 0 1 1 1v3a1 1 0 0 1 -1 1h-6a1 1 0 0 1 -1 -1l0 -3\" /> <path d=\"M10 11v-2a2 2 0 1 1 4 0v2\" />", "document": "<path d=\"M14 3v4a1 1 0 0 0 1 1h4\" /> <path d=\"M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2\" /> <path d=\"M9 9l1 0\" /> <path d=\"M9 13l6 0\" /> <path d=\"M9 17l6 0\" />", "template": "<path d=\"M4 5a1 1 0 0 1 1 -1h14a1 1 0 0 1 1 1v2a1 1 0 0 1 -1 1h-14a1 1 0 0 1 -1 -1l0 -2\" /> <path d=\"M4 13a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -6\" /> <path d=\"M14 12l6 0\" /> <path d=\"M14 16l6 0\" /> <path d=\"M14 20l6 0\" />", "routing": "<path d=\"M3 19a2 2 0 1 0 4 0a2 2 0 0 0 -4 0\" /> <path d=\"M19 7a2 2 0 1 0 0 -4a2 2 0 0 0 0 4\" /> <path d=\"M11 19h5.5a3.5 3.5 0 0 0 0 -7h-8a3.5 3.5 0 0 1 0 -7h4.5\" />", "inbox": "<path d=\"M4 6a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2l0 -12\" /> <path d=\"M4 13h3l3 3h4l3 -3h3\" />", "form": "<path d=\"M12 3a3 3 0 0 0 -3 3v12a3 3 0 0 0 3 3\" /> <path d=\"M6 3a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3\" /> <path d=\"M13 7h7a1 1 0 0 1 1 1v8a1 1 0 0 1 -1 1h-7\" /> <path d=\"M5 7h-1a1 1 0 0 0 -1 1v8a1 1 0 0 0 1 1h1\" /> <path d=\"M17 12h.01\" /> <path d=\"M13 12h.01\" />", "plugin": "<path d=\"M4 7h3a1 1 0 0 0 1 -1v-1a2 2 0 0 1 4 0v1a1 1 0 0 0 1 1h3a1 1 0 0 1 1 1v3a1 1 0 0 0 1 1h1a2 2 0 0 1 0 4h-1a1 1 0 0 0 -1 1v3a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1v-1a2 2 0 0 0 -4 0v1a1 1 0 0 1 -1 1h-3a1 1 0 0 1 -1 -1v-3a1 1 0 0 1 1 -1h1a2 2 0 0 0 0 -4h-1a1 1 0 0 1 -1 -1v-3a1 1 0 0 1 1 -1\" />", "store": "<path d=\"M3 21l18 0\" /> <path d=\"M3 7v1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1m0 1a3 3 0 0 0 6 0v-1h-18l2 -4h14l2 4\" /> <path d=\"M5 21l0 -10.15\" /> <path d=\"M19 21l0 -10.15\" /> <path d=\"M9 21v-4a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v4\" />", "tool": "<path d=\"M7 10h3v-3l-3.5 -3.5a6 6 0 0 1 8 8l6 6a2 2 0 0 1 -3 3l-6 -6a6 6 0 0 1 -8 -8l3.5 3.5\" />", "audit": "<path d=\"M12 8l0 4l2 2\" /> <path d=\"M3.05 11a9 9 0 1 1 .5 4m-.5 5v-5h5\" />", "activity": "<path d=\"M3 12h4l3 8l4 -16l3 8h4\" />", "notification": "<path d=\"M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6\" /> <path d=\"M9 17v1a3 3 0 0 0 6 0v-1\" />", "settings": "<path d=\"M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065\" /> <path d=\"M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0\" />", "branding": "<path d=\"M12 21a9 9 0 0 1 0 -18c4.97 0 9 3.582 9 8c0 1.06 -.474 2.078 -1.318 2.828c-.844 .75 -1.989 1.172 -3.182 1.172h-2.5a2 2 0 0 0 -1 3.75a1.3 1.3 0 0 1 -1 2.25\" /> <path d=\"M7.5 10.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" /> <path d=\"M11.5 7.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" /> <path d=\"M15.5 10.5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" />", "language": "<path d=\"M9 6.371c0 4.418 -2.239 6.629 -5 6.629\" /> <path d=\"M4 6.371h7\" /> <path d=\"M5 9c0 2.144 2.252 3.908 6 4\" /> <path d=\"M12 20l4 -9l4 9\" /> <path d=\"M19.1 18h-6.2\" /> <path d=\"M6.694 3l.793 .582\" />", "billing": "<path d=\"M3 8a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v8a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3l0 -8\" /> <path d=\"M3 10l18 0\" /> <path d=\"M7 15l.01 0\" /> <path d=\"M11 15l2 0\" />", "invoice": "<path d=\"M14 3v4a1 1 0 0 0 1 1h4\" /> <path d=\"M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2\" /> <path d=\"M9 7l1 0\" /> <path d=\"M9 13l6 0\" /> <path d=\"M13 17l2 0\" />", "receipt": "<path d=\"M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16l-3 -2l-2 2l-2 -2l-2 2l-2 -2l-3 2m4 -14h6m-6 4h6m-2 4h2\" />", "quote": "<path d=\"M14 3v4a1 1 0 0 0 1 1h4\" /> <path d=\"M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2\" /> <path d=\"M14 11h-2.5a1.5 1.5 0 0 0 0 3h1a1.5 1.5 0 0 1 0 3h-2.5\" /> <path d=\"M12 17v1m0 -8v1\" />", "lead": "<path d=\"M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0\" /> <path d=\"M16 19h6\" /> <path d=\"M19 16v6\" /> <path d=\"M6 21v-2a4 4 0 0 1 4 -4h4\" />", "customer": "<path d=\"M20 6v12a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2\" /> <path d=\"M10 16h6\" /> <path d=\"M11 11a2 2 0 1 0 4 0a2 2 0 1 0 -4 0\" /> <path d=\"M4 8h3\" /> <path d=\"M4 12h3\" /> <path d=\"M4 16h3\" />", "money": "<path d=\"M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0\" /> <path d=\"M14.8 9a2 2 0 0 0 -1.8 -1h-2a2 2 0 1 0 0 4h2a2 2 0 1 1 0 4h-2a2 2 0 0 1 -1.8 -1\" /> <path d=\"M12 7v10\" />", "chart": "<path d=\"M3 13a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v6a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -6\" /> <path d=\"M15 9a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v10a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -10\" /> <path d=\"M9 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v14a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -14\" /> <path d=\"M4 20h14\" />", "search": "<path d=\"M3 10a7 7 0 1 0 14 0a7 7 0 1 0 -14 0\" /> <path d=\"M21 21l-6 -6\" />", "filter": "<path d=\"M4 4h16v2.172a2 2 0 0 1 -.586 1.414l-4.414 4.414v7l-6 2v-8.5l-4.48 -4.928a2 2 0 0 1 -.52 -1.345v-2.227\" />", "plus": "<path d=\"M12 5l0 14\" /> <path d=\"M5 12l14 0\" />", "check": "<path d=\"M5 12l5 5l10 -10\" />", "close": "<path d=\"M18 6l-12 12\" /> <path d=\"M6 6l12 12\" />", "info": "<path d=\"M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0\" /> <path d=\"M12 9h.01\" /> <path d=\"M11 12h1v4h1\" />", "alert": "<path d=\"M12 9v4\" /> <path d=\"M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0\" /> <path d=\"M12 16h.01\" />", "success": "<path d=\"M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0\" /> <path d=\"M9 12l2 2l4 -4\" />", "error": "<path d=\"M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0\" /> <path d=\"M10 10l4 4m0 -4l-4 4\" />", "view": "<path d=\"M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0\" /> <path d=\"M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6\" />", "hide": "<path d=\"M10.585 10.587a2 2 0 0 0 2.829 2.828\" /> <path d=\"M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87\" /> <path d=\"M3 3l18 18\" />", "undo": "<path d=\"M9 14l-4 -4l4 -4\" /> <path d=\"M5 10h11a4 4 0 1 1 0 8h-1\" />", "send": "<path d=\"M10 14l11 -11\" /> <path d=\"M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5\" />", "edit": "<path d=\"M4 20h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4\" /> <path d=\"M13.5 6.5l4 4\" />", "delete": "<path d=\"M4 7l16 0\" /> <path d=\"M10 11l0 6\" /> <path d=\"M14 11l0 6\" /> <path d=\"M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12\" /> <path d=\"M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3\" />", "more": "<path d=\"M4 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" /> <path d=\"M11 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" /> <path d=\"M18 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" />", "chevron-right": "<path d=\"M9 6l6 6l-6 6\" />", "chevron-left": "<path d=\"M15 6l-6 6l6 6\" />", "chevron-down": "<path d=\"M6 9l6 6l6 -6\" />", "arrow-right": "<path d=\"M5 12l14 0\" /> <path d=\"M13 18l6 -6\" /> <path d=\"M13 6l6 6\" />", "external": "<path d=\"M12 6h-6a2 2 0 0 0 -2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-6\" /> <path d=\"M11 13l9 -9\" /> <path d=\"M15 4h5v5\" />", "download": "<path d=\"M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2\" /> <path d=\"M7 11l5 5l5 -5\" /> <path d=\"M12 4l0 12\" />", "upload": "<path d=\"M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2\" /> <path d=\"M7 9l5 -5l5 5\" /> <path d=\"M12 4l0 12\" />", "calendar": "<path d=\"M4 7a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12\" /> <path d=\"M16 3v4\" /> <path d=\"M8 3v4\" /> <path d=\"M4 11h16\" /> <path d=\"M11 15h1\" /> <path d=\"M12 15v3\" />", "clock": "<path d=\"M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0\" /> <path d=\"M12 7v5l3 3\" />", "lock": "<path d=\"M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6\" /> <path d=\"M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0\" /> <path d=\"M8 11v-4a4 4 0 1 1 8 0v4\" />", "mail": "<path d=\"M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10\" /> <path d=\"M3 7l9 6l9 -6\" />", "phone": "<path d=\"M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1 -2 2a16 16 0 0 1 -15 -15a2 2 0 0 1 2 -2\" />", "whatsapp": "<path d=\"M3 21l1.65 -3.8a9 9 0 1 1 3.4 2.9l-5.05 .9\" /> <path d=\"M9 10a.5 .5 0 0 0 1 0v-1a.5 .5 0 0 0 -1 0v1a5 5 0 0 0 5 5h1a.5 .5 0 0 0 0 -1h-1a.5 .5 0 0 0 0 1\" />", "qr": "<path d=\"M4 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4\" /> <path d=\"M7 17l0 .01\" /> <path d=\"M14 5a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4\" /> <path d=\"M7 7l0 .01\" /> <path d=\"M4 15a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1l0 -4\" /> <path d=\"M17 7l0 .01\" /> <path d=\"M14 14l3 0\" /> <path d=\"M20 14l0 .01\" /> <path d=\"M14 14l0 3\" /> <path d=\"M14 20l3 0\" /> <path d=\"M17 17l3 0\" /> <path d=\"M20 17l0 3\" />", "print": "<path d=\"M17 17h2a2 2 0 0 0 2 -2v-4a2 2 0 0 0 -2 -2h-14a2 2 0 0 0 -2 2v4a2 2 0 0 0 2 2h2\" /> <path d=\"M17 9v-4a2 2 0 0 0 -2 -2h-6a2 2 0 0 0 -2 2v4\" /> <path d=\"M7 15a2 2 0 0 1 2 -2h6a2 2 0 0 1 2 2v4a2 2 0 0 1 -2 2h-6a2 2 0 0 1 -2 -2l0 -4\" />", "logout": "<path d=\"M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2\" /> <path d=\"M9 12h12l-3 -3\" /> <path d=\"M18 15l3 -3\" />", "help": "<path d=\"M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0\" /> <path d=\"M12 16v.01\" /> <path d=\"M12 13a2 2 0 0 0 .914 -3.782a1.98 1.98 0 0 0 -2.414 .483\" />", "copy": "<path d=\"M7 9.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667l0 -8.666\" /> <path d=\"M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1\" />", "link": "<path d=\"M9 15l6 -6\" /> <path d=\"M11 6l.463 -.536a5 5 0 0 1 7.071 7.072l-.534 .464\" /> <path d=\"M13 18l-.397 .534a5.068 5.068 0 0 1 -7.127 0a4.972 4.972 0 0 1 0 -7.071l.524 -.463\" />", "sync": "<path d=\"M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4\" /> <path d=\"M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4\" />", "google": "<path d=\"M20.945 11a9 9 0 1 1 -3.284 -5.997l-2.655 2.392a5.5 5.5 0 1 0 2.119 6.605h-4.125v-3h7.945\" />", "microsoft": "<path d=\"M17.8 20l-12 -1.5c-1 -.1 -1.8 -.9 -1.8 -1.9v-9.2c0 -1 .8 -1.8 1.8 -1.9l12 -1.5c1.2 -.1 2.2 .8 2.2 1.9v12.1c0 1.2 -1.1 2.1 -2.2 1.9l0 .1\" /> <path d=\"M12 5l0 14\" /> <path d=\"M4 12l16 0\" />", "two-step": "<path d=\"M11.46 20.846a12 12 0 0 1 -7.96 -14.846a12 12 0 0 0 8.5 -3a12 12 0 0 0 8.5 3a12 12 0 0 1 -.09 7.06\" /> <path d=\"M15 19l2 2l4 -4\" />", "offline": "<path d=\"M12 18l.01 0\" /> <path d=\"M9.172 15.172a4 4 0 0 1 5.656 0\" /> <path d=\"M6.343 12.343a7.963 7.963 0 0 1 3.864 -2.14m4.163 .155a7.965 7.965 0 0 1 3.287 2\" /> <path d=\"M3.515 9.515a12 12 0 0 1 3.544 -2.455m3.101 -.92a12 12 0 0 1 10.325 3.374\" /> <path d=\"M3 3l18 18\" />", "maintenance": "<path d=\"M3 21h4l13 -13a1.5 1.5 0 0 0 -4 -4l-13 13v4\" /> <path d=\"M14.5 5.5l4 4\" /> <path d=\"M12 8l-5 -5l-4 4l5 5\" /> <path d=\"M7 8l-1.5 1.5\" /> <path d=\"M16 12l5 5l-4 4l-5 -5\" /> <path d=\"M16 17l-1.5 1.5\" />", "mail-open": "<path d=\"M3 9l9 6l9 -6l-9 -6l-9 6\" /> <path d=\"M21 9v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10\" /> <path d=\"M3 19l6 -6\" /> <path d=\"M15 13l6 6\" />", "unlock": "<path d=\"M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2l0 -6\" /> <path d=\"M11 16a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" /> <path d=\"M8 11v-5a4 4 0 0 1 8 0\" />", "world": "<path d=\"M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0\" /> <path d=\"M3.6 9h16.8\" /> <path d=\"M3.6 15h16.8\" /> <path d=\"M11.5 3a17 17 0 0 0 0 18\" /> <path d=\"M12.5 3a17 17 0 0 1 0 18\" />", "robot": "<path stroke=\"none\" d=\"M0 0h24v24H0z\" fill=\"none\" />\n  <path d=\"M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2\" />\n  <path d=\"M9 16c1 .667 2 1 3 1s2 -.333 3 -1\" />\n  <path d=\"M9 7l-1 -4\" />\n  <path d=\"M15 7l1 -4\" />\n  <path d=\"M9 12v-1\" />\n  <path d=\"M15 12v-1\" />", "chevron-up": "<path d=\"M6 15l6 -6l6 6\" />", "minus": "<path d=\"M5 12l14 0\" />", "sun": "<path d=\"M8 12a4 4 0 1 0 8 0a4 4 0 1 0 -8 0\" /> <path d=\"M3 12h1m8 -9v1m8 8h1m-9 8v1m-6.4 -15.4l.7 .7m12.1 -.7l-.7 .7m0 11.4l.7 .7m-12.1 -.7l-.7 .7\" />", "moon": "<path d=\"M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454l0 .008\" />", "desktop": "<path d=\"M3 5a1 1 0 0 1 1 -1h16a1 1 0 0 1 1 1v10a1 1 0 0 1 -1 1h-16a1 1 0 0 1 -1 -1v-10\" /> <path d=\"M7 20h10\" /> <path d=\"M9 16v4\" /> <path d=\"M15 16v4\" />", "arrow-up": "<path d=\"M12 5l0 14\" /> <path d=\"M18 11l-6 -6\" /> <path d=\"M6 11l6 -6\" />", "arrow-down": "<path d=\"M12 5l0 14\" /> <path d=\"M18 13l-6 6\" /> <path d=\"M6 13l6 6\" />", "sort": "<path d=\"M8 9l4 -4l4 4\" /> <path d=\"M16 15l-4 4l-4 -4\" />", "zoom-in": "<path d=\"M3 10a7 7 0 1 0 14 0a7 7 0 1 0 -14 0\" /> <path d=\"M7 10l6 0\" /> <path d=\"M10 7l0 6\" /> <path d=\"M21 21l-6 -6\" />", "zoom-out": "<path d=\"M3 10a7 7 0 1 0 14 0a7 7 0 1 0 -14 0\" /> <path d=\"M7 10l6 0\" /> <path d=\"M21 21l-6 -6\" />", "menu": "<path d=\"M4 6l16 0\" /> <path d=\"M4 12l16 0\" /> <path d=\"M4 18l16 0\" />", "bold": "<path d=\"M7 5h6a3.5 3.5 0 0 1 0 7h-6l0 -7\" /> <path d=\"M13 12h1a3.5 3.5 0 0 1 0 7h-7v-7\" />", "italic": "<path d=\"M11 5l6 0\" /> <path d=\"M7 19l6 0\" /> <path d=\"M14 5l-4 14\" />", "underline": "<path d=\"M7 5v5a5 5 0 0 0 10 0v-5\" /> <path d=\"M5 19h14\" />", "strike": "<path d=\"M5 12l14 0\" /> <path d=\"M16 6.5a4 2 0 0 0 -4 -1.5h-1a3.5 3.5 0 0 0 0 7h2a3.5 3.5 0 0 1 0 7h-1.5a4 2 0 0 1 -4 -1.5\" />", "list-bullet": "<path d=\"M9 6l11 0\" /> <path d=\"M9 12l11 0\" /> <path d=\"M9 18l11 0\" /> <path d=\"M5 6l0 .01\" /> <path d=\"M5 12l0 .01\" /> <path d=\"M5 18l0 .01\" />", "list-numbered": "<path d=\"M11 6h9\" /> <path d=\"M11 12h9\" /> <path d=\"M12 18h8\" /> <path d=\"M4 16a2 2 0 1 1 4 0c0 .591 -.5 1 -1 1.5l-3 2.5h4\" /> <path d=\"M6 10v-6l-2 2\" />", "list-todo": "<path d=\"M3.5 5.5l1.5 1.5l2.5 -2.5\" /> <path d=\"M3.5 11.5l1.5 1.5l2.5 -2.5\" /> <path d=\"M3.5 17.5l1.5 1.5l2.5 -2.5\" /> <path d=\"M11 6l9 0\" /> <path d=\"M11 12l9 0\" /> <path d=\"M11 18l9 0\" />", "blockquote": "<path d=\"M6 15h15\" /> <path d=\"M21 19h-15\" /> <path d=\"M15 11h6\" /> <path d=\"M21 7h-6\" /> <path d=\"M9 9h1a1 1 0 1 1 -1 1v-2.5a2 2 0 0 1 2 -2\" /> <path d=\"M3 9h1a1 1 0 1 1 -1 1v-2.5a2 2 0 0 1 2 -2\" />", "code": "<path d=\"M7 8l-4 4l4 4\" /> <path d=\"M17 8l4 4l-4 4\" /> <path d=\"M14 4l-4 16\" />", "table": "<path d=\"M3 5a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-14\" /> <path d=\"M3 10h18\" /> <path d=\"M10 3v18\" />", "image": "<path d=\"M15 8h.01\" /> <path d=\"M3 6a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-12\" /> <path d=\"M3 16l5 -5c.928 -.893 2.072 -.893 3 0l5 5\" /> <path d=\"M14 14l1 -1c.928 -.893 2.072 -.893 3 0l3 3\" />", "heading-1": "<path d=\"M19 18v-8l-2 2\" /> <path d=\"M4 6v12\" /> <path d=\"M12 6v12\" /> <path d=\"M11 18h2\" /> <path d=\"M3 18h2\" /> <path d=\"M4 12h8\" /> <path d=\"M3 6h2\" /> <path d=\"M11 6h2\" />", "heading-2": "<path d=\"M17 12a2 2 0 1 1 4 0c0 .591 -.417 1.318 -.816 1.858l-3.184 4.143l4 0\" /> <path d=\"M4 6v12\" /> <path d=\"M12 6v12\" /> <path d=\"M11 18h2\" /> <path d=\"M3 18h2\" /> <path d=\"M4 12h8\" /> <path d=\"M3 6h2\" /> <path d=\"M11 6h2\" />", "heading-3": "<path d=\"M19 14a2 2 0 1 0 -2 -2\" /> <path d=\"M17 16a2 2 0 1 0 2 -2\" /> <path d=\"M4 6v12\" /> <path d=\"M12 6v12\" /> <path d=\"M11 18h2\" /> <path d=\"M3 18h2\" /> <path d=\"M4 12h8\" /> <path d=\"M3 6h2\" /> <path d=\"M11 6h2\" />", "page-break": "<path d=\"M14 3v4a1 1 0 0 0 1 1h4\" /> <path d=\"M19 18v1a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-1\" /> <path d=\"M3 14h3m4.5 0h3m4.5 0h3\" /> <path d=\"M5 10v-5a2 2 0 0 1 2 -2h7l5 5v2\" />", "columns": "<path d=\"M3 4a1 1 0 0 1 1 -1h16a1 1 0 0 1 1 1v16a1 1 0 0 1 -1 1h-16a1 1 0 0 1 -1 -1v-16\" /> <path d=\"M12 3v18\" />", "comment": "<path d=\"M3 20l1.3 -3.9c-2.324 -3.437 -1.426 -7.872 2.1 -10.374c3.526 -2.501 8.59 -2.296 11.845 .48c3.255 2.777 3.695 7.266 1.029 10.501c-2.666 3.235 -7.615 4.215 -11.574 2.293l-4.7 1\" />", "comment-add": "<path d=\"M8 9h8\" /> <path d=\"M8 13h6\" /> <path d=\"M12.01 18.594l-4.01 2.406v-3h-2a3 3 0 0 1 -3 -3v-8a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v5.5\" /> <path d=\"M16 19h6\" /> <path d=\"M19 16v6\" />", "drag": "<path d=\"M8 5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" /> <path d=\"M8 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" /> <path d=\"M8 19a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" /> <path d=\"M14 5a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" /> <path d=\"M14 12a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" /> <path d=\"M14 19a1 1 0 1 0 2 0a1 1 0 1 0 -2 0\" />", "redo": "<path d=\"M15 14l4 -4l-4 -4\" /> <path d=\"M19 10h-11a4 4 0 1 0 0 8h1\" />", "text": "<path d=\"M6 4l12 0\" /> <path d=\"M12 4l0 16\" />", "divider": "<path d=\"M3 12l0 .01\" /> <path d=\"M7 12l10 0\" /> <path d=\"M21 12l0 .01\" />", "field": "<path d=\"M7 4a2 2 0 0 0 -2 2v3a2 3 0 0 1 -2 3a2 3 0 0 1 2 3v3a2 2 0 0 0 2 2\" /> <path d=\"M17 4a2 2 0 0 1 2 2v3a2 3 0 0 0 2 3a2 3 0 0 0 -2 3v3a2 2 0 0 1 -2 2\" />", "highlight": "<path d=\"M3 19h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4\" /> <path d=\"M12.5 5.5l4 4\" /> <path d=\"M4.5 13.5l4 4\" /> <path d=\"M21 15v4h-8l4 -4l4 0\" />", "outline": "<path d=\"M9 6h11\" /> <path d=\"M12 12h8\" /> <path d=\"M15 18h5\" /> <path d=\"M5 6v.01\" /> <path d=\"M8 12v.01\" /> <path d=\"M11 18v.01\" />", "suggest": "<path d=\"M14 3v4a1 1 0 0 0 1 1h4\" /> <path d=\"M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2\" /> <path d=\"M10 18l5 -5a1.414 1.414 0 0 0 -2 -2l-5 5v2h2\" />", "page": "<path d=\"M14 3v4a1 1 0 0 0 1 1h4\" /> <path d=\"M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2\" /> <path d=\"M9 9l1 0\" /> <path d=\"M9 13l6 0\" /> <path d=\"M9 17l6 0\" />", "continuous": "<path d=\"M4 6a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2l0 -12\" /> <path d=\"M4 12l16 0\" />"};
  var WORD = {"vb": "0 0 94.94 29.26", "d": "M81.21 12.06L81.15 11.90L81.14 11.66L81.16 11.50L81.22 11.35L81.31 11.21L81.42 11.10L81.56 11.01L81.71 10.94L81.95 10.91L83.91 10.91L84.06 10.94L84.21 11.00L84.40 11.14L84.50 11.26L84.58 11.40L87.39 18.06L87.77 19.07L88.10 18.07L90.44 11.45L90.50 11.31L90.59 11.19L90.70 11.08L90.83 11.00L90.98 10.94L91.13 10.91L93.21 10.91L93.45 10.97L93.65 11.09L93.81 11.27L93.91 11.49L93.94 11.64L93.94 11.80L93.88 12.03L89.28 23.00L87.40 27.74L87.33 27.89L87.23 28.01L87.11 28.12L86.89 28.22L86.65 28.26L85.14 28.26L84.98 28.24L84.75 28.16L84.62 28.07L84.46 27.89L84.39 27.75L84.34 27.60L84.33 27.44L84.35 27.28L84.39 27.13L86.16 22.99ZM74.56 10.91L74.58 10.89L74.59 8.58L74.65 8.35L74.77 8.14L74.88 8.03L75.01 7.94L75.16 7.88L75.32 7.85L77.10 7.85L77.33 7.91L77.47 7.98L77.59 8.08L77.73 8.27L77.81 8.50L77.83 8.66L77.83 10.91L79.51 10.91L79.74 10.94L79.89 11.00L80.08 11.15L80.22 11.34L80.31 11.56L80.32 11.72L80.32 13.08L80.29 13.24L80.22 13.38L80.08 13.57L79.96 13.67L79.82 13.75L79.59 13.81L77.83 13.81L77.83 22.27L77.77 22.50L77.65 22.70L77.53 22.82L77.40 22.90L77.18 22.98L77.02 23.00L75.39 23.00L75.24 22.98L75.08 22.94L74.94 22.86L74.77 22.70L74.68 22.57L74.60 22.35L74.58 22.19L74.58 13.81L73.13 13.81L72.90 13.78L72.68 13.67L72.50 13.51L72.42 13.38L72.34 13.16L72.32 13.00L72.32 11.64L72.36 11.48L72.42 11.34L72.56 11.15L72.68 11.05L72.82 10.97L73.05 10.91ZM67.68 8.28L67.52 8.25L67.37 8.20L67.17 8.07L67.02 7.88L66.93 7.66L66.90 7.45L66.91 5.81L66.95 5.57L67.06 5.36L67.17 5.25L67.30 5.15L67.52 5.06L67.68 5.04L69.38 5.04L69.61 5.08L69.82 5.19L69.94 5.30L70.07 5.50L70.12 5.65L70.15 5.81L70.15 7.51L70.10 7.74L69.99 7.95L69.88 8.07L69.75 8.16L69.61 8.23L69.46 8.27ZM70.15 22.19L70.13 22.35L70.09 22.50L70.01 22.64L69.91 22.76L69.79 22.86L69.65 22.94L69.50 22.98L69.34 23.00L67.71 23.00L67.48 22.97L67.26 22.86L67.09 22.70L67.00 22.57L66.92 22.35L66.90 22.19L66.91 11.64L66.97 11.41L67.09 11.21L67.26 11.05L67.40 10.97L67.56 10.92L67.71 10.91L69.34 10.91L69.50 10.92L69.72 11.00L69.91 11.15L70.05 11.34L70.13 11.56L70.15 11.72ZM53.45 23.00L53.29 22.98L53.14 22.94L53.00 22.86L52.88 22.76L52.74 22.57L52.68 22.42L52.64 22.27L52.64 6.82L52.70 6.59L52.82 6.38L53.00 6.22L53.22 6.12L53.45 6.09L55.13 6.09L55.36 6.15L55.50 6.22L55.63 6.32L55.77 6.51L55.83 6.66L55.86 6.82L55.86 12.43L56.22 12.05L56.60 11.73L57.10 11.38L57.55 11.13L58.14 10.89L58.61 10.76L59.09 10.68L59.60 10.66L60.23 10.69L60.83 10.79L61.38 10.96L61.75 11.13L62.16 11.39L62.56 11.74L62.82 12.05L63.12 12.53L63.36 13.07L63.54 13.65L63.63 14.12L63.72 14.84L63.75 15.62L63.75 22.27L63.71 22.42L63.65 22.57L63.51 22.76L63.39 22.86L63.25 22.94L63.02 23.00L61.31 23.00L61.16 22.98L61.00 22.94L60.86 22.86L60.74 22.76L60.64 22.64L60.54 22.42L60.50 22.19L60.50 15.72L60.46 15.21L60.38 14.77L60.24 14.39L60.09 14.13L59.88 13.88L59.65 13.71L59.33 13.56L58.98 13.48L58.65 13.45L58.16 13.46L57.81 13.51L57.42 13.62L57.06 13.78L56.78 13.96L56.49 14.22L56.26 14.51L56.07 14.83L55.97 15.13L55.91 15.44L55.89 15.75L55.89 22.19L55.87 22.35L55.79 22.57L55.70 22.70L55.53 22.86L55.39 22.94L55.23 22.98L55.07 23.00ZM31.64 7.00L31.88 7.04L32.10 7.14L32.28 7.30L32.36 7.43L32.42 7.58L34.98 16.27L35.18 17.09L35.36 18.12L35.59 17.09L35.93 15.96L38.29 9.83L38.41 9.62L38.59 9.45L38.81 9.34L39.05 9.31L40.76 9.31L40.93 9.33L41.08 9.37L41.23 9.45L41.35 9.56L41.45 9.69L41.52 9.83L43.86 16.05L44.18 17.04L44.43 18.00L44.57 17.16L44.89 15.92L47.33 7.58L47.39 7.44L47.48 7.30L47.65 7.14L47.79 7.06L48.03 7.00L49.95 7.00L50.11 7.04L50.26 7.10L50.45 7.24L50.55 7.36L50.65 7.58L50.69 7.81L50.65 8.05L46.23 22.43L46.17 22.57L46.03 22.76L45.91 22.86L45.77 22.94L45.54 23.00L44.06 23.00L43.83 22.97L43.68 22.90L43.49 22.76L43.39 22.64L43.31 22.50L39.76 14.00L36.43 22.48L36.36 22.63L36.20 22.81L36.06 22.90L35.84 22.98L35.68 23.00L34.14 23.00L33.91 22.94L33.77 22.86L33.65 22.76L33.55 22.64L33.45 22.43L29.03 8.05L29.00 7.89L29.01 7.66L29.06 7.50L29.13 7.36L29.23 7.24L29.36 7.14L29.57 7.04L29.81 7.00Z"};

  function cx() { var o = []; for (var i = 0; i < arguments.length; i++) { if (arguments[i]) o.push(arguments[i]); } return o.join(' '); }
  function omit(obj, keys) { var o = {}; for (var k in obj) { if (Object.prototype.hasOwnProperty.call(obj, k) && keys.indexOf(k) === -1) o[k] = obj[k]; } return o; }
  var seq = 0; function useId(p) { var r = React.useRef(null); if (!r.current) { seq += 1; r.current = (p || 'wy') + '-' + seq; } return r.current; }

  /* ---------- Brand ---------- */
  var WORD2 = {"vb": "0 0 66.94 25.22", "d": "M53.21 8.02L53.15 7.87L53.14 7.62L53.16 7.47L53.22 7.31L53.31 7.18L53.42 7.06L53.56 6.97L53.71 6.91L53.95 6.87L55.91 6.88L56.06 6.91L56.21 6.97L56.40 7.11L56.50 7.23L56.58 7.37L59.39 14.02L59.77 15.04L60.10 14.04L62.44 7.41L62.50 7.27L62.59 7.15L62.70 7.04L62.83 6.96L62.98 6.90L63.13 6.88L65.21 6.88L65.45 6.93L65.65 7.06L65.81 7.23L65.91 7.45L65.94 7.61L65.94 7.77L65.88 8.00L61.28 18.96L59.40 23.71L59.33 23.85L59.23 23.98L59.11 24.08L58.89 24.19L58.65 24.22L57.14 24.22L56.98 24.21L56.75 24.12L56.62 24.03L56.46 23.86L56.39 23.71L56.34 23.56L56.33 23.40L56.35 23.24L56.39 23.09L58.16 18.95ZM46.56 6.87L46.58 6.85L46.59 4.54L46.65 4.31L46.77 4.11L46.88 3.99L47.01 3.91L47.16 3.84L47.32 3.81L49.10 3.81L49.33 3.87L49.47 3.95L49.59 4.05L49.73 4.24L49.81 4.46L49.83 4.62L49.83 6.87L51.51 6.87L51.74 6.91L51.89 6.97L52.08 7.11L52.22 7.30L52.31 7.53L52.32 7.68L52.32 9.04L52.29 9.20L52.22 9.35L52.08 9.54L51.96 9.64L51.82 9.71L51.59 9.77L49.83 9.78L49.83 18.23L49.77 18.46L49.65 18.67L49.53 18.78L49.40 18.87L49.18 18.95L49.02 18.96L47.39 18.96L47.24 18.95L47.08 18.90L46.94 18.83L46.77 18.67L46.68 18.53L46.60 18.31L46.58 18.15L46.58 9.78L45.13 9.78L44.90 9.74L44.68 9.64L44.50 9.48L44.42 9.35L44.34 9.12L44.32 8.96L44.32 7.60L44.36 7.45L44.42 7.30L44.56 7.11L44.68 7.01L44.82 6.93L45.05 6.88ZM39.68 4.24L39.52 4.22L39.37 4.16L39.17 4.03L39.02 3.85L38.93 3.63L38.90 3.41L38.91 1.77L38.95 1.54L39.06 1.33L39.17 1.21L39.30 1.11L39.52 1.02L39.68 1.00L41.38 1.00L41.61 1.05L41.82 1.16L41.94 1.27L42.07 1.46L42.12 1.61L42.15 1.77L42.15 3.47L42.10 3.70L41.99 3.91L41.88 4.03L41.75 4.13L41.61 4.20L41.46 4.23ZM42.15 18.15L42.13 18.31L42.09 18.46L42.01 18.60L41.91 18.73L41.79 18.83L41.65 18.90L41.50 18.95L41.34 18.96L39.71 18.96L39.48 18.93L39.26 18.83L39.09 18.67L39.00 18.53L38.92 18.31L38.90 18.15L38.91 7.60L38.97 7.37L39.09 7.17L39.26 7.01L39.40 6.93L39.56 6.89L39.71 6.87L41.34 6.87L41.50 6.89L41.72 6.97L41.91 7.11L42.05 7.30L42.13 7.53L42.15 7.68ZM25.45 18.96L25.29 18.95L25.14 18.90L25.00 18.83L24.88 18.73L24.74 18.53L24.68 18.39L24.64 18.23L24.64 2.78L24.70 2.55L24.82 2.35L25.00 2.19L25.22 2.08L25.45 2.05L27.13 2.05L27.36 2.11L27.50 2.19L27.63 2.29L27.77 2.48L27.83 2.63L27.86 2.78L27.86 8.40L28.22 8.02L28.60 7.69L29.10 7.34L29.55 7.10L30.14 6.86L30.61 6.73L31.09 6.65L31.60 6.62L32.23 6.65L32.83 6.75L33.38 6.92L33.75 7.09L34.16 7.35L34.56 7.71L34.82 8.02L35.12 8.49L35.36 9.03L35.54 9.61L35.63 10.08L35.72 10.80L35.75 11.59L35.75 18.23L35.71 18.39L35.65 18.53L35.51 18.73L35.39 18.83L35.25 18.90L35.02 18.96L33.31 18.96L33.16 18.95L33.00 18.90L32.86 18.83L32.74 18.73L32.64 18.60L32.54 18.39L32.50 18.15L32.50 11.68L32.46 11.18L32.38 10.74L32.24 10.36L32.09 10.10L31.88 9.85L31.65 9.68L31.33 9.53L30.98 9.44L30.65 9.41L30.16 9.42L29.81 9.47L29.42 9.58L29.06 9.75L28.78 9.93L28.49 10.19L28.26 10.47L28.07 10.80L27.97 11.10L27.91 11.40L27.89 11.71L27.89 18.15L27.87 18.31L27.79 18.53L27.70 18.67L27.53 18.83L27.39 18.90L27.23 18.95L27.07 18.96ZM3.64 2.96L3.88 3.00L4.10 3.10L4.28 3.26L4.36 3.40L4.42 3.55L6.98 12.23L7.18 13.06L7.36 14.09L7.59 13.05L7.93 11.92L10.29 5.79L10.41 5.58L10.59 5.41L10.81 5.31L11.05 5.27L12.76 5.27L12.93 5.29L13.08 5.34L13.23 5.42L13.35 5.52L13.45 5.65L13.52 5.80L15.86 12.02L16.18 13.00L16.43 13.97L16.57 13.13L16.89 11.88L19.33 3.55L19.39 3.40L19.48 3.26L19.65 3.10L19.79 3.03L20.03 2.97L21.95 2.97L22.11 3.00L22.26 3.06L22.45 3.20L22.55 3.33L22.65 3.54L22.69 3.78L22.65 4.01L18.23 18.39L18.17 18.54L18.03 18.73L17.91 18.83L17.77 18.90L17.54 18.96L16.06 18.96L15.83 18.93L15.68 18.87L15.49 18.73L15.39 18.60L15.31 18.47L11.76 9.96L8.43 18.45L8.36 18.59L8.20 18.77L8.06 18.86L7.84 18.95L7.68 18.96L6.14 18.96L5.91 18.90L5.77 18.83L5.65 18.73L5.55 18.60L5.45 18.39L1.03 4.01L1.00 3.86L1.01 3.62L1.06 3.47L1.13 3.33L1.23 3.20L1.36 3.10L1.57 3.00L1.81 2.96Z"};
  /* The mark, from the locked numbers: side 8 · offset 3 · radius 2 · edge 1 (module m). Master on a 24-unit canvas
     (m = 2), pixel-fitted cuts below 24px: [canvas, side, offset, edge, radius]. */
  var CUTS = { main: [24, 16, 6, 2, 4, 1], c20: [20, 14, 5, 2, 3.5, 0.5], c16: [16, 11, 4, 2, 3, 0.5] };
  function cutFor(size) { return size >= 24 ? 'main' : size >= 20 ? 'c20' : 'c16'; }
  function planes(cut, dx, dy) {
    var c = CUTS[cut]; dx = dx || 0; dy = dy || 0; var p = c[5], s = c[1], o = c[2], e = c[3], r = c[4];
    return [
      h('rect', { key: 'p', className: 'wy-mark__sub', x: p + dx, y: p + o + dy, width: s, height: s, rx: r }),
      h('rect', { key: 'e', className: 'wy-mark__edge', x: p + o + dx, y: p + dy, width: s, height: s, rx: r }),
      h('rect', { key: 'w', className: 'wy-mark__win', x: p + o + e + dx, y: p + e + dy, width: s - 2 * e, height: s - 2 * e, rx: r - e })
    ];
  }
  function Mark(props) {
    var size = props.size || 32; var cut = props.cut || cutFor(size); var C = CUTS[cut][0];
    return h('svg', { className: cx('wy-mark', 'wy-mark--' + (props.tone || 'auto'), props.className), viewBox: '0 0 ' + C + ' ' + C, width: size, height: size, role: 'img', 'aria-label': props.label || 'Whity' }, planes(cut));
  }
  /* Lockup: the word's cap line and baseline are the platform's top and bottom; gap 3 m. `height` is the mark height. */
  function Lockup(props) {
    var height = props.height || 32; var tone = 'wy-mark--' + (props.tone || 'auto');
    if (props.layout === 'stacked') {
      var v2 = WORD2.vb.split(' ').map(Number); var k = height / 24;
      return h('span', { className: cx('wy-lockup', 'wy-lockup--stacked', tone, props.className), role: 'img', 'aria-label': 'Whity', style: { gap: 6 * k + 'px' } },
        h('svg', { className: 'wy-mark', viewBox: '0 0 24 24', width: height, height: height, 'aria-hidden': 'true' }, planes('main')),
        h('svg', { viewBox: WORD2.vb, width: v2[2] * k, height: v2[3] * k, 'aria-hidden': 'true' }, h('path', { className: 'wy-lockup__word', d: WORD2.d })));
    }
    var vb = WORD.vb.split(' ').map(Number);
    return h('svg', { className: cx('wy-lockup', tone, props.className), viewBox: WORD.vb, width: vb[2] * height / 24, height: vb[3] * height / 24, role: 'img', 'aria-label': 'Whity' },
      planes('main'), h('path', { className: 'wy-lockup__word', d: WORD.d }));
  }
  function BuiltOnWhity(props) {
    return h('a', { className: cx('wy-builton', props.className), href: props.href || 'https://whity.dev' },
      h(Mark, { size: 16, tone: 'mono' }), h('span', null, props.label || 'Built on Whity'));
  }
  /* Icons that point a direction mirror in right-to-left layouts (icons.json: mirrorsInRtl). */
  var MIRROR = { 'chevron-left': 1, 'chevron-right': 1, 'arrow-right': 1, 'send': 1, 'undo': 1, 'redo': 1, 'external': 1, 'logout': 1, 'list-bullet': 1, 'list-numbered': 1, 'list-todo': 1, 'blockquote': 1, 'outline': 1 };
  function Icon(props) {
    var body = ICONS[props.name] || ''; var mir = props.mirror != null ? props.mirror : !!MIRROR[props.name];
    return h('svg', { className: cx('wy-icon', mir && 'wy-icon--mirror', props.className), viewBox: '0 0 24 24', width: props.size || 16, height: props.size || 16, fill: 'none', stroke: 'currentColor', strokeWidth: props.stroke || ((props.size || 16) <= 16 ? 2.25 : 2), strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': props.label ? undefined : 'true', role: props.label ? 'img' : undefined, 'aria-label': props.label, dangerouslySetInnerHTML: { __html: body } });
  }
  function Blueprint(props) {
    return h('section', { className: cx('wy-blueprint', props.className) },
      props.label ? h('div', { className: 'wy-blueprint__label' }, props.label) : null,
      props.title ? h('h3', { className: 'wy-blueprint__title' }, props.title) : null,
      props.children);
  }

  /* ---------- Actions ---------- */
  function Button(props) {
    var variant = props.variant || 'default'; var size = props.size || 'default';
    var rest = omit(props, ['variant', 'size', 'loading', 'icon', 'iconEnd', 'className', 'children']);
    var isz = size === 'lg' ? 16 : 14;
    return h('button', Object.assign({ type: 'button' }, rest, { className: cx('wy-btn', 'wy-btn--' + variant, 'wy-btn--size-' + size, props.className), disabled: props.disabled || props.loading, 'aria-busy': props.loading ? true : undefined }),
      props.loading ? h('span', { className: 'wy-spinner', 'aria-hidden': 'true' }) : (props.icon ? h(Icon, { name: props.icon, size: isz }) : null),
      props.children, props.iconEnd ? h(Icon, { name: props.iconEnd, size: isz, mirror: true }) : null);
  }
  /* The label is the accessible name and shows as a tooltip (Tooltip is decorative here, so it is not read twice). */
  function IconButton(props) {
    var rest = omit(props, ['icon', 'label', 'variant', 'className', 'size', 'tooltip', 'shortcut']);
    var btn = h('button', Object.assign({ type: 'button', 'aria-label': props.label }, rest, { className: cx('wy-btn', 'wy-btn--' + (props.variant || 'ghost'), 'wy-btn--icon', props.size === 'sm' && 'wy-btn--icon-sm', props.className) }), h(Icon, { name: props.icon, size: 16 }));
    return props.tooltip === false ? btn : h(Tooltip, { label: props.label, shortcut: props.shortcut, decorative: true, side: props.tooltip === 'bottom' ? 'bottom' : 'top' }, btn);
  }

  /* ---------- Status ---------- */
  function Badge(props) { var v = props.variant || 'default'; return h('span', { className: cx('wy-badge', 'wy-badge--' + v, props.className) }, props.icon ? h(Icon, { name: props.icon, size: 12 }) : null, props.children); }
  function Money(props) {
    var n = Number(props.amount || 0); var dec = props.decimals != null ? props.decimals : ((props.currency || 'JOD') === 'JOD' ? 3 : 2); var s = n.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    return h('span', { className: cx('wy-money', n < 0 && 'wy-money--neg', props.className) }, h('span', { className: 'wy-money__cur' }, props.currency || 'JOD'), ' ', s);
  }
  function Skeleton(props) { return h('span', { className: cx('wy-skeleton', props.className), style: { width: props.width || '100%', height: props.height || 12 }, 'aria-hidden': 'true' }); }

  /* ---------- Surfaces ---------- */
  function Card(props) {
    var rest = omit(props, ['title', 'description', 'footer', 'interactive', 'selected', 'variant', 'className', 'children', 'action']);
    var isButton = props.interactive && props.onClick;
    return h(isButton ? 'button' : 'div', Object.assign({}, rest, { type: isButton ? 'button' : undefined, className: cx('wy-card', props.variant === 'outline' && 'wy-card--outline', props.interactive && 'wy-card--interactive', props.selected && 'wy-card--selected', props.className) }),
      (props.title || props.description || props.action) ? h('div', { className: 'wy-card__header' }, h('div', { className: 'wy-card__heads' }, props.title ? h('div', { className: 'wy-card__title' }, props.title) : null, props.description ? h('div', { className: 'wy-card__desc' }, props.description) : null), props.action ? h('div', { className: 'wy-card__action' }, props.action) : null) : null,
      props.children ? h('div', { className: 'wy-card__content' }, props.children) : null,
      props.footer ? h('div', { className: 'wy-card__footer' }, props.footer) : null);
  }
  function Dialog(props) {
    return h('div', { className: cx('wy-dialog', props.inline && 'wy-dialog--inline'), role: 'dialog', 'aria-modal': props.inline ? undefined : 'true', 'aria-labelledby': 'wy-dlg-t' },
      h('div', { className: 'wy-dialog__panel' },
        h('div', { className: 'wy-dialog__head' }, h('h2', { id: 'wy-dlg-t', className: 'wy-dialog__title' }, props.title), props.onClose ? h(IconButton, { icon: 'close', label: 'Close', onClick: props.onClose }) : null),
        props.description ? h('p', { className: 'wy-dialog__desc' }, props.description) : null,
        h('div', { className: 'wy-dialog__body' }, props.children),
        props.footer ? h('div', { className: 'wy-dialog__foot' }, props.footer) : null));
  }
  function EmptyState(props) {
    return h('div', { className: cx('wy-empty', props.className) }, h('div', { className: 'wy-empty__icon' }, h(Icon, { name: props.icon || 'inbox', size: 24 })), h('div', { className: 'wy-empty__title' }, props.title), props.description ? h('p', { className: 'wy-empty__desc' }, props.description) : null, props.action || null);
  }

  /* ---------- Forms ---------- */
  function Field(props, control) {
    return h('div', { className: cx('wy-field', props.className) },
      props.label ? h('label', { className: 'wy-field__label', htmlFor: props._id }, props.label, props.required ? h('span', { className: 'wy-field__req', 'aria-hidden': 'true' }, ' *') : null) : null,
      control,
      (props.errorText || props.helperText) ? h('div', { id: props._id + '-note', className: props.errorText ? 'wy-field__error' : 'wy-field__help' }, props.errorText || props.helperText) : null);
  }
  function Input(props) {
    var id = useId('wy-in'); var p = Object.assign({}, props, { _id: props.id || id });
    var rest = omit(props, ['label', 'helperText', 'errorText', 'required', 'className', 'id']);
    return Field(p, h('input', Object.assign({}, rest, { id: p._id, className: cx('wy-input', props.errorText && 'wy-input--invalid'), 'aria-invalid': props.errorText ? true : undefined, 'aria-describedby': (props.errorText || props.helperText) ? p._id + '-note' : undefined, required: props.required })));
  }
  function Select(props) {
    var id = useId('wy-sel'); var p = Object.assign({}, props, { _id: props.id || id });
    var rest = omit(props, ['label', 'helperText', 'errorText', 'required', 'className', 'id', 'options']);
    return Field(p, h('span', { className: 'wy-select' }, h('select', Object.assign({}, rest, { id: p._id, className: 'wy-input' }), (props.options || []).map(function (o) { return h('option', { key: o.value, value: o.value }, o.label); })), h(Icon, { name: 'chevron-down', size: 14, className: 'wy-select__chev' })));
  }
  function Switch(props) {
    var st = useState(!!props.defaultChecked); var on = props.checked != null ? props.checked : st[0];
    return h('label', { className: 'wy-switch' }, h('button', { type: 'button', role: 'switch', 'aria-checked': on, className: 'wy-switch__track', onClick: function () { st[1](!on); if (props.onChange) props.onChange(!on); } }, h('span', { className: 'wy-switch__thumb' })), props.label ? h('span', null, props.label) : null);
  }
  function Checkbox(props) {
    var id = useId('wy-cb'); var ref = React.useRef(null);
    React.useEffect(function () { if (ref.current) ref.current.indeterminate = !!props.indeterminate; });
    return h('label', { className: cx('wy-check', props.indeterminate && 'is-mixed', props.className), htmlFor: id }, h('input', Object.assign({ type: 'checkbox', id: id, ref: ref }, omit(props, ['label', 'indeterminate', 'className']))),
      h('span', { className: 'wy-check__box', 'aria-hidden': 'true' }, h(Icon, { name: props.indeterminate ? 'minus' : 'check', size: 12, stroke: 3 })), props.label != null ? h('span', null, props.label) : null);
  }

  /* ---------- Navigation ---------- */
  function Tabs(props) {
    var st = useState(props.defaultValue || (props.items && props.items[0] && props.items[0].value)); var cur = st[0];
    return h('div', { className: 'wy-tabs', role: 'tablist' }, (props.items || []).map(function (t) {
      var on = t.value === cur;
      return h('button', { key: t.value, type: 'button', role: 'tab', 'aria-selected': on, className: cx('wy-tabs__tab', on && 'is-on'), onClick: function () { st[1](t.value); if (props.onChange) props.onChange(t.value); } }, t.label, t.count != null ? h('span', { className: 'wy-tabs__count' }, t.count) : null);
    }));
  }
  function Breadcrumb(props) {
    var items = props.items || [];
    return h('nav', { className: 'wy-crumbs', 'aria-label': 'Breadcrumb' }, h('ol', null, items.map(function (it, i) {
      var last = i === items.length - 1;
      return h('li', { key: i }, last ? h('span', { 'aria-current': 'page' }, it.label) : h('a', { href: it.href || '#' }, it.label), last ? null : h(Icon, { name: 'chevron-right', size: 12, mirror: true }));
    })));
  }
  function Pagination(props) {
    return h('nav', { className: 'wy-pager', 'aria-label': 'Pages' }, h('span', { className: 'wy-pager__info' }, props.label || (props.from + '–' + props.to + ' of ' + props.total)),
      h(IconButton, { icon: 'chevron-left', label: 'Previous page', variant: 'outline', className: 'wy-icon--mirror', disabled: props.from <= 1 }),
      h(IconButton, { icon: 'chevron-right', label: 'Next page', variant: 'outline', className: 'wy-icon--mirror', disabled: props.to >= props.total }));
  }
  function PageHeader(props) {
    return h('header', { className: 'wy-pagehead' }, props.breadcrumb ? h(Breadcrumb, { items: props.breadcrumb }) : null,
      h('div', { className: 'wy-pagehead__row' }, h('div', { className: 'wy-pagehead__text' }, h('h1', { className: 'wy-pagehead__title' }, props.title), props.description ? h('p', { className: 'wy-pagehead__desc' }, props.description) : null), props.actions ? h('div', { className: 'wy-pagehead__actions' }, props.actions) : null));
  }
  /* 4.5: sidebar 240px; collapsed rail 56px below 1024px (or `collapsed`); a drawer opened from the top bar below 768px (or `mobile`). */
  function AppShell(props) {
    var nav = props.nav || []; var open = useState(!!props.navOpen); var col = !!props.collapsed;
    var L = Object.assign({ menu: 'Menu', close: 'Close menu', main: 'Main' }, props.labels || {});
    function item(it) {
      var a = h('a', { key: it.label, href: '#', className: cx('wy-shell__item', it.active && 'is-on'), 'aria-current': it.active ? 'page' : undefined, 'aria-label': col ? it.label + (it.count ? ' (' + it.count + ')' : '') : undefined },
        h(Icon, { name: it.icon, size: 16 }), h('span', { className: 'wy-shell__label' }, it.label), it.count ? h('span', { className: 'wy-shell__count' }, it.count) : null);
      return col ? h(Tooltip, { key: it.label, label: it.label, side: 'end', decorative: true }, a) : a;
    }
    return h('div', { className: cx('wy-shell', col && 'wy-shell--collapsed', props.mobile && 'wy-shell--mobile', open[0] && 'is-open'), dir: props.dir },
      h('div', { className: 'wy-shell__scrim', 'aria-hidden': 'true', onClick: function () { open[1](false); } }),
      h('aside', { className: 'wy-shell__side' },
        h('div', { className: 'wy-shell__brand' }, col ? (props.brandMark || h(Mark, { size: 24 })) : (props.brand || h(Lockup, { height: 24 })), h(IconButton, { icon: 'close', label: L.close, size: 'sm', className: 'wy-shell__close', onClick: function () { open[1](false); } })),
        props.tenant ? h('button', { type: 'button', className: 'wy-shell__tenant', 'aria-label': col ? props.tenant : undefined }, h('span', { className: 'wy-shell__tenant-logo', 'aria-hidden': 'true' }, String(props.tenant).charAt(0)), h('span', { className: 'wy-shell__label' }, props.tenant), h(Icon, { name: 'sort', size: 14, className: 'wy-shell__chev' })) : null,
        h('nav', { className: 'wy-shell__nav', 'aria-label': L.main }, nav.map(function (g, gi) {
          return h('div', { key: gi, className: 'wy-shell__group' }, g.label ? h('div', { className: 'wy-shell__glabel' }, g.label) : null, g.items.map(item));
        })),
        props.user ? h('div', { className: 'wy-shell__user' }, h('span', { className: 'wy-actor__avatar wy-actor__avatar--lg', 'aria-hidden': 'true' }, initials(props.user)), h('div', { className: 'wy-shell__label' }, h('div', { className: 'wy-shell__uname' }, props.user), props.role ? h('div', { className: 'wy-shell__urole' }, props.role) : null)) : null),
      h('div', { className: 'wy-shell__main' },
        h('div', { className: 'wy-shell__top' }, h(IconButton, { icon: 'menu', label: L.menu, className: 'wy-shell__menu', 'aria-expanded': open[0], onClick: function () { open[1](true); } }),
          h('label', { className: 'wy-shell__search' }, h(Icon, { name: 'search', size: 14 }), h('input', { placeholder: props.searchLabel || 'Search', 'aria-label': props.searchLabel || 'Search' }), h('kbd', { dir: 'ltr' }, '⌘K')), h('div', { className: 'wy-shell__topacts' }, props.topActions)),
        h('main', { className: 'wy-shell__body' }, props.children)),
      props.builtOn ? h('div', { className: 'wy-shell__builton' }, h(BuiltOnWhity, null)) : null);
  }


  /* ---------- Data ---------- */
  /* 7.6 and the stage 7 data table: 44px rows, sticky header, sort on click, checkbox selection with a bulk bar at the foot
     (count, money total, actions), row menu at the end, empty / loading / error states, and a totals row for money. */
  function DataTable(props) {
    var cols = props.columns || [], rows = props.rows || [], state = props.state || 'ready', selectable = !!props.selectable;
    var sel = useState(props.defaultSelected || []), sort = useState(props.defaultSort || null);
    var L = Object.assign({ selectAll: 'Select all rows', selectRow: 'Select row', selected: '{n} selected', clear: 'Clear', error: 'These rows didn’t load.', retry: 'Try again', rowMenu: 'Row actions', total: 'Total', empty: 'Nothing here yet' }, props.labels || {});
    function rid(r, i) { return r.id != null ? r.id : i; }
    var ids = rows.map(rid);
    var list = rows.map(function (r, i) { return { r: r, id: rid(r, i) }; });
    if (sort[0]) { var sc = cols.filter(function (c) { return c.key === sort[0].key; })[0]; var dir = sort[0].dir === 'desc' ? -1 : 1;
      list.sort(function (a, b) { var x = sc && sc.sortValue ? sc.sortValue(a.r) : a.r[sort[0].key], y = sc && sc.sortValue ? sc.sortValue(b.r) : b.r[sort[0].key]; return (x > y ? 1 : x < y ? -1 : 0) * dir; }); }
    var nSel = sel[0].length, all = selectable && rows.length > 0 && nSel === rows.length, some = nSel > 0 && !all;
    function toggle(id) { var cur = sel[0].slice(), k = cur.indexOf(id); if (k >= 0) cur.splice(k, 1); else cur.push(id); sel[1](cur); if (props.onSelect) props.onSelect(cur); }
    function sortBy(c) { var cur = sort[0]; sort[1](cur && cur.key === c.key ? { key: c.key, dir: cur.dir === 'asc' ? 'desc' : 'asc' } : { key: c.key, dir: 'asc' }); }
    function endCls(c) { return c.align === 'end' ? 'is-end' : undefined; }
    var ncols = cols.length + (selectable ? 1 : 0) + (props.rowMenu ? 1 : 0);
    var head = h('tr', null, selectable ? h('th', { className: 'wy-table__sel', scope: 'col' }, h(Checkbox, { checked: all, indeterminate: some, disabled: state !== 'ready' || !rows.length, onChange: function () { var v = all ? [] : ids.slice(); sel[1](v); if (props.onSelect) props.onSelect(v); }, 'aria-label': L.selectAll })) : null,
      cols.map(function (c) { var s = sort[0] && sort[0].key === c.key ? sort[0].dir : null;
        return h('th', { key: c.key, scope: 'col', className: endCls(c), style: c.width ? { width: c.width } : undefined, 'aria-sort': c.sortable ? (s === 'asc' ? 'ascending' : s === 'desc' ? 'descending' : 'none') : undefined },
          c.sortable ? h('button', { type: 'button', className: cx('wy-table__sort', s && 'is-on'), onClick: function () { sortBy(c); } }, c.label, h(Icon, { name: s === 'asc' ? 'arrow-up' : s === 'desc' ? 'arrow-down' : 'sort', size: 12 })) : c.label); }),
      props.rowMenu ? h('th', { className: 'wy-table__menu', scope: 'col' }, h('span', { className: 'wy-sr' }, L.rowMenu)) : null);
    var body;
    if (state === 'loading') body = [0, 1, 2, 3, 4].map(function (k) { return h('tr', { key: k, 'aria-hidden': 'true' }, selectable ? h('td', { className: 'wy-table__sel' }, h(Skeleton, { width: 16, height: 16 })) : null,
      cols.map(function (c) { return h('td', { key: c.key, className: endCls(c) }, h(Skeleton, { width: c.align === 'end' ? 64 : (k % 2 ? '55%' : '75%'), height: 10 })); }), props.rowMenu ? h('td', null) : null); });
    else if (state === 'error') body = h('tr', null, h('td', { colSpan: ncols, className: 'wy-table__state' }, h('div', { className: 'wy-table__err', role: 'alert' }, h(Icon, { name: 'error', size: 16 }), h('span', null, props.errorText || L.error), h(Button, { variant: 'outline', size: 'sm', icon: 'sync', onClick: props.onRetry }, L.retry))));
    else if (!rows.length) body = h('tr', null, h('td', { colSpan: ncols, className: 'wy-table__state' }, props.empty || h(EmptyState, { title: L.empty })));
    else body = list.map(function (o) { var r = o.r, on = sel[0].indexOf(o.id) >= 0;
      return h('tr', { key: o.id, className: cx(on && 'is-selected', props.onRowClick && 'is-clickable'), 'aria-selected': selectable ? on : undefined,
        onClick: props.onRowClick ? function (e) { if (e.target.closest && e.target.closest('button, input, label, a')) return; props.onRowClick(r); } : undefined },
        selectable ? h('td', { className: 'wy-table__sel' }, h(Checkbox, { checked: on, onChange: function () { toggle(o.id); }, 'aria-label': L.selectRow })) : null,
        cols.map(function (c) { return h('td', { key: c.key, className: endCls(c) }, c.render ? c.render(r) : r[c.key]); }),
        props.rowMenu ? h('td', { className: 'wy-table__menu' }, typeof props.rowMenu === 'function' ? props.rowMenu(r) : h(IconButton, { icon: 'more', label: L.rowMenu, size: 'sm' })) : null); });
    var foot = props.totals && state === 'ready' && rows.length ? h('tfoot', null, h('tr', null, selectable ? h('td', null) : null, cols.map(function (c, k) {
      var v = props.totals[c.key]; return h('td', { key: c.key, className: endCls(c) }, v != null ? v : k === 0 ? (props.totalsLabel || L.total) : null); }), props.rowMenu ? h('td', null) : null)) : null;
    var chosen = list.filter(function (o) { return sel[0].indexOf(o.id) >= 0; }).map(function (o) { return o.r; });
    var bar = selectable && nSel ? h('div', { className: 'wy-bulkbar wy-table__bulk', role: 'region', 'aria-label': fill(L.selected, { n: nSel }) },
      h('span', { className: 'wy-bulkbar__count' }, fill(L.selected, { n: nSel }), props.selectionSum ? h('span', { className: 'wy-bulkbar__sum' }, ' · ', props.selectionSum(chosen)) : null),
      typeof props.bulkActions === 'function' ? props.bulkActions(chosen) : props.bulkActions || null,
      h(Button, { variant: 'ghost', size: 'sm', onClick: function () { sel[1]([]); if (props.onSelect) props.onSelect([]); } }, L.clear)) : null;
    return h('div', { className: cx('wy-table-wrap', props.sticky && 'wy-table-wrap--sticky', props.className), style: props.maxHeight ? { maxHeight: props.maxHeight } : undefined },
      h('table', { className: 'wy-table', 'aria-busy': state === 'loading' || undefined, 'aria-label': props.label }, h('thead', null, head), h('tbody', null, body), foot), bar, props.footer || null);
  }
  function PluginCard(props) {
    return h('div', { className: 'wy-plugin' }, h('div', { className: 'wy-plugin__icon' }, h(Icon, { name: props.icon || 'plugin', size: 18 })),
      h('div', { className: 'wy-plugin__body' }, h('div', { className: 'wy-plugin__name' }, props.name), h('div', { className: 'wy-plugin__by' }, props.by), props.description ? h('p', { className: 'wy-plugin__desc' }, props.description) : null),
      h('div', { className: 'wy-plugin__foot' }, h(Badge, { variant: props.state === 'active' ? 'success' : props.state === 'failed' ? 'destructive' : 'outline' }, props.stateLabel || props.state), props.price ? h('span', { className: 'wy-plugin__price' }, props.price) : null));
  }

  /* ---------- Feedback ---------- */
  var ALERT_ICON = { default: 'info', info: 'info', success: 'success', warning: 'alert', destructive: 'error' };
  function Alert(props) {
    var v = props.variant || 'default';
    return h('div', { role: 'alert', className: cx('wy-alert', 'wy-alert--' + v, props.className) }, h(Icon, { name: ALERT_ICON[v] || 'info', className: 'wy-alert__icon' }),
      h('div', { className: 'wy-alert__body' }, props.title ? h('div', { className: 'wy-alert__title' }, props.title) : null, props.children ? h('div', { className: 'wy-alert__desc' }, props.children) : null),
      props.action ? h('div', { className: 'wy-alert__action' }, props.action) : null);
  }

  /* ---------- Agent UI ---------- */
  function AgentGlyph(props) {
    return h('span', { className: 'wy-agent-glyph', 'aria-hidden': 'true' }, h(Icon, { name: 'robot', size: props.size || 12, stroke: 2.25 }));
  }
  function initials(name) { return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(function (s) { return s.charAt(0); }).join('').toUpperCase(); }
  function ActorChip(props) {
    var kind = props.kind || 'person';
    return h('span', { className: cx('wy-actor', 'wy-actor--' + kind, props.className) },
      kind === 'agent' ? h(AgentGlyph, null) : kind === 'system' ? h('span', { className: 'wy-actor__sys', 'aria-hidden': 'true' }, h(Mark, { size: 16, cut: 'c16', label: '' })) : h('span', { className: 'wy-actor__avatar', 'aria-hidden': 'true' }, initials(props.name)),
      h('span', { className: 'wy-actor__name' }, props.name),
      kind === 'agent' && props.onBehalfOf ? h('span', { className: 'wy-actor__for' }, (props.forLabel || 'for') + ' ' + props.onBehalfOf) : null,
      h('span', { className: 'wy-sr' }, kind === 'agent' ? ' (agent)' : kind === 'system' ? ' (system)' : ' (person)'));
  }
  function ActionProposal(props) {
    var st = useState(props.status || 'proposed'); var status = st[0]; var set = st[1];
    var L = Object.assign({ waiting: 'Waiting for your approval', approved: 'Approved', rejected: 'Declined', undone: 'Undone', expired: 'Expired', askAgain: 'Ask again', approve: 'Approve', reject: 'Decline', edit: 'Edit', undo: 'Undo', scope: 'Uses', reversible: 'Can be undone', reversibleFor: 'Can be undone for {t}', irreversible: 'Cannot be undone', before: 'Now', after: 'After', showAll: 'Show all {n} changes', showLess: 'Show less' }, props.labels || {});
    /* 7.5a: summary first, at most two changes, then Show all; always the permissions used and the undo window. */
    var more = useState(false); var allCh = props.changes || []; var shownCh = more[0] || allCh.length <= 3 ? allCh : allCh.slice(0, 2);
    function act(n, cb) { return function () { set(n); if (cb) cb(); }; }
    var badge = { proposed: h(Badge, { variant: 'outline' }, h('span', { className: 'wy-dot wy-dot--pending', 'aria-hidden': 'true' }), L.waiting), approved: h(Badge, { variant: 'success', icon: 'check' }, L.approved + (props.decidedAt ? ' · ' + props.decidedAt : '')), rejected: h(Badge, { variant: 'secondary', icon: 'close' }, L.rejected), undone: h(Badge, { variant: 'secondary', icon: 'undo' }, L.undone + (props.undoneBy ? ' by ' + props.undoneBy : '')), expired: h(Badge, { variant: 'secondary', icon: 'clock' }, L.expired) }[status];
    return h('article', { className: cx('wy-proposal', 'wy-proposal--' + status, props.className), 'aria-label': props.title, dir: props.dir },
      h('header', { className: 'wy-proposal__head' }, h(ActorChip, { kind: 'agent', name: props.agent, onBehalfOf: props.onBehalfOf, forLabel: L.forLabel }), badge),
      h('h3', { className: 'wy-proposal__title' }, props.title),
      props.summary ? h('p', { className: 'wy-proposal__summary' }, props.summary) : null,
      shownCh.length ? h('dl', { className: 'wy-proposal__changes' }, shownCh.map(function (c, i) {
        return h('div', { key: i, className: 'wy-proposal__change' }, h('dt', null, c.label), h('dd', null,
          c.before != null ? h('span', { className: 'wy-proposal__before' }, h('span', { className: 'wy-sr' }, L.before + ': '), c.before) : null,
          c.before != null ? h(Icon, { name: 'arrow-right', size: 12, className: 'wy-proposal__arrow wy-icon--mirror' }) : null,
          h('span', { className: 'wy-proposal__after' }, c.before != null ? h('span', { className: 'wy-sr' }, L.after + ': ') : null, c.after)));
      })) : null,
      allCh.length > 3 ? h('button', { type: 'button', className: 'wy-link wy-proposal__more', 'aria-expanded': more[0], onClick: function () { more[1](!more[0]); } }, more[0] ? L.showLess : fill(L.showAll, { n: allCh.length })) : null,
      h('footer', { className: 'wy-proposal__foot' },
        h('div', { className: 'wy-proposal__meta' }, props.scope && props.scope.length ? h('span', { className: 'wy-proposal__scope' }, L.scope + ' ', props.scope.map(function (s, i) { return h('code', { key: i }, s); })) : null, h('span', null, props.reversible === false ? L.irreversible : props.undoWindow ? fill(L.reversibleFor, { t: props.undoWindow }) : L.reversible)),
        status === 'proposed' ? h('div', { className: 'wy-proposal__actions' }, h(Button, { variant: 'ghost', size: 'sm', onClick: act('rejected', props.onReject) }, L.reject), h(Button, { variant: 'outline', size: 'sm', icon: 'edit', onClick: props.onEdit }, L.edit), h(Button, { size: 'sm', icon: 'check', onClick: act('approved', props.onApprove) }, L.approve))
          : status === 'approved' && props.reversible !== false ? h('div', { className: 'wy-proposal__actions' }, h(Button, { variant: 'ghost', size: 'sm', icon: 'undo', onClick: act('undone', props.onUndo) }, L.undo))
          : status === 'expired' ? h('div', { className: 'wy-proposal__actions' }, h(Button, { variant: 'outline', size: 'sm', icon: 'sync', onClick: act('proposed', props.onAskAgain) }, L.askAgain)) : null));
  }
  var KIND_ICON = { read: 'view', propose: 'edit', act: 'send', approve: 'check', undo: 'undo', error: 'alert' };
  /* Filter by agent: chips for All and each agent in the list (`filter`). */
  function AgentActivity(props) {
    var items = props.items || []; var who = useState(props.defaultAgent || null);
    var agents = []; items.forEach(function (it) { if ((it.actorKind || 'agent') === 'agent' && agents.indexOf(it.actor) < 0) agents.push(it.actor); });
    var L = Object.assign({ all: 'All', filter: 'Filter by agent' }, props.labels || {});
    var shown = who[0] ? items.filter(function (it) { return it.actor === who[0] || it.onBehalfOfAgent === who[0]; }) : items;
    var list = h('ol', { className: cx('wy-activity', props.className), 'aria-label': props.label || 'Agent activity' }, shown.map(function (it, i) {
      var k = it.kind || 'read';
      return h('li', { key: i, className: cx('wy-activity__item', 'wy-activity__item--' + k) }, h('span', { className: 'wy-activity__node', 'aria-hidden': 'true' }, h(Icon, { name: KIND_ICON[k] || 'info', size: 12 })),
        h('div', { className: 'wy-activity__body' }, h('div', { className: 'wy-activity__line' }, h(ActorChip, { kind: it.actorKind || 'agent', name: it.actor, onBehalfOf: it.onBehalfOf }), h('span', { className: 'wy-activity__text' }, it.text)), h('div', { className: 'wy-activity__meta' }, h('time', null, it.time), it.tool ? h('code', null, it.tool) : null)));
    }));
    if (!props.filter || agents.length < 2) return list;
    return h('div', { className: 'wy-activity-wrap' }, h('div', { className: 'wy-activity__filter', role: 'radiogroup', 'aria-label': L.filter },
      [null].concat(agents).map(function (a) { var on = who[0] === a; return h('button', { key: a || '_all', type: 'button', role: 'radio', 'aria-checked': on, className: cx('wy-chipbtn', on && 'is-on'), onClick: function () { who[1](a); } }, a ? h(AgentGlyph, null) : null, a || L.all); })), list);
  }

  var MODES = ['off', 'ask', 'allow'];
  function ScopeGrant(props) {
    var L = Object.assign({ off: 'Off', ask: 'Ask first', allow: 'Allowed', note: 'The agent can never do more than the person who granted it.' }, props.labels || {});
    var init = {}; (props.grants || []).forEach(function (g) { (g.actions || []).forEach(function (a) { init[g.key + ':' + a.key] = a.mode || 'off'; }); });
    var st = useState(init); var modes = st[0];
    function set(k, m) { var n = Object.assign({}, modes); n[k] = m; st[1](n); if (props.onChange) props.onChange(k, m); }
    return h('div', { className: cx('wy-scope', props.className) },
      h('div', { className: 'wy-scope__head' }, h(ActorChip, { kind: 'agent', name: props.agent, onBehalfOf: props.onBehalfOf }), h('span', { className: 'wy-scope__note' }, L.note)),
      h('table', { className: 'wy-scope__table' }, h('tbody', null, (props.grants || []).map(function (g) {
        return (g.actions || []).map(function (a, j) {
          var k = g.key + ':' + a.key;
          return h('tr', { key: k }, j === 0 ? h('th', { scope: 'row', rowSpan: g.actions.length, className: 'wy-scope__res' }, g.label) : null,
            h('td', { className: 'wy-scope__act' }, a.label, h('code', null, k)),
            h('td', { className: 'wy-scope__modes' }, h('div', { className: 'wy-seg', role: 'radiogroup', 'aria-label': g.label + ' · ' + a.label }, MODES.map(function (m) {
              var on = modes[k] === m;
              return h('button', { key: m, type: 'button', role: 'radio', 'aria-checked': on, className: cx('wy-seg__opt', on && 'is-on', 'wy-seg__opt--' + m), onClick: function () { set(k, m); } }, L[m]);
            }))));
        });
      }))));
  }

  /*@DOC-START*/
  /* ---------- Documents (Whity Render) ----------
     Stage 6 rules: 16 mm margins; letterhead (tenant at the start, title block at the end); tenant colour on the
     title only; either language leads, the other gives small labels; rules-only line table; totals block 46% wide
     with the amount in words in both languages; stamp, reference and page at the start of the foot, QR at the end;
     continuation pages with a running header, the table header repeated, brought/carried forward. */
  var DOC_L = {
    taxInvoice: { en: 'Tax invoice', ar: 'فاتورة ضريبية' }, quote: { en: 'Quote', ar: 'عرض سعر' }, receipt: { en: 'Receipt', ar: 'سند قبض' }, creditNote: { en: 'Credit note', ar: 'إشعار دائن' },
    billTo: { en: 'Bill to', ar: 'العميل' }, preparedFor: { en: 'Prepared for', ar: 'مقدَّم إلى' }, receivedFrom: { en: 'Received from', ar: 'استلمنا من' }, customer: { en: 'Customer', ar: 'العميل' },
    due: { en: 'Due', ar: 'تاريخ الاستحقاق' }, terms: { en: 'Payment terms', ar: 'شروط الدفع' }, validUntil: { en: 'Valid until', ar: 'صالح حتى' }, delivery: { en: 'Delivery', ar: 'التسليم' },
    quoteRef: { en: 'Quote', ar: 'عرض السعر' }, poRef: { en: 'Order', ar: 'أمر الشراء' }, originalInvoice: { en: 'Original invoice', ar: 'الفاتورة الأصلية' }, reason: { en: 'Reason', ar: 'السبب' },
    method: { en: 'Payment method', ar: 'طريقة الدفع' }, receivedBy: { en: 'Received by', ar: 'المستلِم' },
    desc: { en: 'Description', ar: 'الوصف' }, qty: { en: 'Qty', ar: 'الكمية' }, unit: { en: 'Unit price', ar: 'سعر الوحدة' }, amount: { en: 'Amount', ar: 'المبلغ' },
    invoice: { en: 'Invoice', ar: 'الفاتورة' }, date: { en: 'Date', ar: 'التاريخ' }, invoiceTotal: { en: 'Invoice total', ar: 'قيمة الفاتورة' }, paidNow: { en: 'Paid now', ar: 'المدفوع الآن' }, stillDue: { en: 'Still due', ar: 'المتبقي' },
    subtotal: { en: 'Subtotal', ar: 'المجموع' }, tax16: { en: 'Sales tax 16%', ar: 'ضريبة المبيعات 16%' }, total: { en: 'Total JOD', ar: 'الإجمالي (دينار)' }, credit: { en: 'Credit JOD', ar: 'قيمة الإشعار (دينار)' },
    received: { en: 'Amount received', ar: 'المبلغ المستلَم' }, balance: { en: 'Balance on account', ar: 'الرصيد المتبقي' },
    notes: { en: 'Notes', ar: 'ملاحظات' }, termsBlock: { en: 'Terms', ar: 'الشروط' }, acceptance: { en: 'Accepted by', ar: 'وافق عليه' },
    name: { en: 'Name', ar: 'الاسم' }, signature: { en: 'Signature', ar: 'التوقيع' }, stampSign: { en: 'Signature and stamp', ar: 'التوقيع والختم' },
    reference: { en: 'Reference', ar: 'المرجع' }, page: { en: 'Page {p} of {n}', ar: 'صفحة {p} من {n}' }, continued: { en: 'continued', ar: 'تابع' },
    carried: { en: 'Carried forward', ar: 'مُرحَّل إلى الصفحة التالية' }, brought: { en: 'Brought forward', ar: 'منقول من الصفحة السابقة' },
    jofotara: { en: 'Issued through JoFotara', ar: 'صدرت عبر JoFotara' }, verify: { en: 'Scan to verify', ar: 'امسح للتحقق' }, acceptOnline: { en: 'Scan to accept online', ar: 'امسح للموافقة على العرض' },
    taxNo: { en: 'Tax no.', ar: 'الرقم الضريبي' }
  };
  function docLabel(l) { return typeof l === 'string' ? (DOC_L[l] || { en: l, ar: l }) : l; }
  function docText(v, lead) { if (v == null || typeof v !== 'object' || React.isValidElement(v) || Array.isArray(v)) return v; return v[lead] != null ? v[lead] : v.en; }
  function docFill(s, vars) { return String(s).replace(/\{(\w+)\}/g, function (m, k) { return vars[k]; }); }
  function docNum(n, d) { return Number(n).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }); }
  function otherLang(lead) { return lead === 'ar' ? 'en' : 'ar'; }
  /* A bilingual label: the lead language, then the other one small. */
  function DocLabel(props) {
    var l = docLabel(props.l); if (!l) return null; var lead = props.lead || 'en', o = otherLang(lead), vars = props.vars || {};
    return h(props.as || 'span', { className: cx('wy-doc__bi', props.className) }, h('span', { lang: lead }, docFill(l[lead] != null ? l[lead] : l.en, vars)), l[o] && props.single !== true ? h('small', { lang: o, dir: o === 'ar' ? 'rtl' : 'ltr' }, docFill(l[o], vars)) : null);
  }

  /* Amount in words for JOD (1 dinar = 1000 fils), English and Arabic. Arabic follows accounting usage
     (tafqeet): the noun form follows the last two digits. Have an Arabic-speaking accountant review before shipping. */
  var EN1 = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
  var EN10 = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
  function en999(n) { var hd = Math.floor(n / 100), r = n % 100, s = [];
    if (hd) s.push(EN1[hd] + ' hundred');
    if (r) { var t = r < 20 ? EN1[r] : EN10[Math.floor(r / 10)] + (r % 10 ? '-' + EN1[r % 10] : ''); s.push(hd ? 'and ' + t : t); }
    return s.join(' '); }
  function enInt(n) { if (!n) return 'zero'; var sc = ['', ' thousand', ' million', ' billion'], parts = [], i = 0, unitsSet = false;
    while (n > 0) { var g = n % 1000; if (g) { parts.unshift(en999(g) + sc[i]); if (i === 0 && g < 100) unitsSet = true; } n = Math.floor(n / 1000); i++; }
    if (unitsSet && parts.length > 1) parts[parts.length - 1] = 'and ' + parts[parts.length - 1];
    return parts.join(' '); }
  var AR1 = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة'];
  var AR10 = ['', 'عشرة', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
  var AR100 = ['', 'مئة', 'مئتان', 'ثلاثمئة', 'أربعمئة', 'خمسمئة', 'ستمئة', 'سبعمئة', 'ثمانمئة', 'تسعمئة'];
  function ar99(n) { if (n <= 10) return AR1[n]; if (n === 11) return 'أحد عشر'; if (n === 12) return 'اثنا عشر'; if (n < 20) return AR1[n - 10] + ' عشر';
    var u = n % 10, t = Math.floor(n / 10); return u ? AR1[u] + ' و' + AR10[t] : AR10[t]; }
  function ar999(n) { var hd = Math.floor(n / 100), r = n % 100, p = []; if (hd) p.push(AR100[hd]); if (r) p.push(ar99(r)); return p.join(' و'); }
  var AR_SC = [null, ['ألف', 'ألفان', 'آلاف', 'ألفاً'], ['مليون', 'مليونان', 'ملايين', 'مليوناً']];
  function arInt(n) { if (!n) return 'صفر'; var g = []; while (n > 0) { g.push(n % 1000); n = Math.floor(n / 1000); }
    var parts = [];
    for (var k = g.length - 1; k >= 0; k--) {
      var c = g[k]; if (!c) continue; var sc = AR_SC[k], rest = 0; for (var j = 0; j < k; j++) rest += g[j];
      var w;
      if (!sc) w = ar999(c);
      else if (c === 1) w = sc[0];
      else if (c === 2) w = rest ? sc[1] : sc[1].replace(/ان$/, 'ا');
      else { var c2 = c % 100; w = ar999(c) + ' ' + (c2 >= 3 && c2 <= 10 ? sc[2] : c2 >= 11 && rest ? sc[3] : sc[0]); }
      if (c % 1000 === 200 && (sc || !rest)) w = w.replace('مئتان', 'مئتا');
      parts.push(w);
    }
    return parts.join(' و'); }
  function arCount(n, f) { var n2 = n % 100;
    if (n === 1) return f[0]; if (n === 2) return f[1];
    return arInt(n) + ' ' + (n2 >= 3 && n2 <= 10 ? f[2] : n2 >= 11 ? f[3] : f[4]); }
  function amountInWords(amount, lang) {
    var t = Math.round(Math.abs(Number(amount)) * 1000), d = Math.floor(t / 1000), f = t % 1000;
    if (lang === 'ar') {
      var a = []; if (d || !f) a.push(arCount(d, ['دينار أردني واحد', 'ديناران أردنيان', 'دنانير أردنية', 'ديناراً أردنياً', 'دينار أردني']));
      if (f) a.push(arCount(f, ['فلس واحد', 'فلسان', 'فلسات', 'فلساً', 'فلس']));
      return a.join(' و');
    }
    var e = []; if (d || !f) e.push(enInt(d) + ' Jordanian ' + (d === 1 ? 'dinar' : 'dinars')); if (f) e.push(enInt(f) + ' fils');
    var s = e.join(' and '); return s.charAt(0).toUpperCase() + s.slice(1);
  }

  function DocLogo(props) {
    var t = props.tenant || {}; var name = docText(t.name, props.lead);
    return h('span', { className: cx('wy-doc__logo', props.small && 'wy-doc__logo--s') }, t.logo || h('span', { className: 'wy-doc__logo-tile', 'aria-hidden': 'true' }, t.initial || String(name || '?').charAt(0)), name);
  }
  function DocQR(props) { return h('div', { className: 'wy-doc__qr' }, h('img', { src: props.src, alt: props.alt || 'QR code' }), h(DocLabel, { l: props.label || 'verify', lead: props.lead })); }
  /* Signature lines: acceptance on a quote, "received by" on a receipt. */
  function DocSign(props) {
    var lead = props.lead || 'en';
    return h('div', { className: 'wy-doc__sign' }, props.title ? h(DocLabel, { l: props.title, lead: lead, className: 'wy-doc__label' }) : null,
      h('div', { className: 'wy-doc__sign-row', style: { '--cols': (props.fields || []).length } }, (props.fields || []).map(function (f, i) { var fl = typeof f === 'object' && f.l ? f : { l: f };
        return h('div', { key: i, className: 'wy-doc__sign-f' }, h('div', { className: 'wy-doc__sign-line' }, fl.value ? h('bdi', null, fl.value) : null), h(DocLabel, { l: fl.l, lead: lead })); })));
  }
  function DocNote(props) { return h('div', { className: 'wy-doc__note' }, props.label ? h(DocLabel, { l: props.label, lead: props.lead, className: 'wy-doc__label' }) : null, h('div', null, props.children)); }
  /* The receipt's headline amount, with words in both languages. */
  function DocAmount(props) {
    var lead = props.lead || 'en', o = otherLang(lead);
    return h('div', { className: 'wy-doc__amount' }, h(DocLabel, { l: props.label || 'received', lead: lead, className: 'wy-doc__label' }),
      h('div', { className: 'wy-doc__amount-n' }, h('bdi', null, (props.currency || 'JOD') + ' ' + docNum(props.value, 3))),
      h('div', { className: 'wy-doc__words wy-doc__words--start' }, h('span', { lang: lead, dir: lead === 'ar' ? 'rtl' : 'ltr' }, amountInWords(props.value, lead)), h('span', { lang: o, dir: o === 'ar' ? 'rtl' : 'ltr' }, amountInWords(props.value, o))));
  }

  function docCell(col, row, lead) {
    var v = docText(row[col.key], lead); if (v == null || v === '') return '';
    if (col.type === 'money') return docNum(v, 3);
    if (col.type === 'percent') return docNum(v, 1) + '%';
    if (col.mono) return h('bdi', { className: 'wy-doc__mono' }, v);
    return v;
  }
  function isEnd(c) { return c.align === 'end' || c.type === 'money' || c.type === 'percent'; }
  /* A rules-only table: used for line items and for any secondary table (ageing, approval trail, report tables). */
  function DocTable(props) {
    var lead = props.lead || 'en', cols = props.columns || [];
    return h('div', { className: 'wy-doc__tblock' }, props.title ? h(DocLabel, { l: props.title, lead: lead, className: 'wy-doc__label' }) : null,
      h('table', { className: cx('wy-doc__table', props.compact && 'wy-doc__table--compact') },
        h('thead', null, h('tr', null, cols.map(function (c) { return h('th', { key: c.key, className: isEnd(c) ? 'wy-doc__n' : null, style: c.width ? { width: c.width } : null }, h(DocLabel, { l: c.label, lead: lead })); }))),
        h('tbody', null, props.before || null, (props.rows || []).map(function (r, k) { return h('tr', { key: k, className: r._strong ? 'wy-doc__strong' : null }, cols.map(function (c) { return h('td', { key: c.key, className: isEnd(c) ? 'wy-doc__n' : null }, docCell(c, r, lead)); })); }))));
  }
  function docHead(props, p, lead) {
    var o = otherLang(lead), t = props.tenant || {}, title = props.title ? docLabel(props.title) : null;
    var no = props.number || props.date ? h('div', { className: 'wy-doc__no' }, props.number ? h('bdi', { className: 'wy-doc__mono' }, props.number) : null, props.number && props.date ? ' · ' : null, props.date ? h('bdi', null, docText(props.date, lead)) : null) : null;
    if (p > 0) return h('header', { className: 'wy-doc__run' }, h(DocLogo, { tenant: t, lead: lead, small: true }), h('span', null, props.number ? h('bdi', { className: 'wy-doc__mono' }, props.number) : null, props.number ? ' · ' : null, title ? title[lead] + ' · ' : null, docLabel('continued')[lead]));
    return h('header', { className: 'wy-doc__head' },
      h('div', { className: 'wy-doc__brand' }, h(DocLogo, { tenant: t, lead: lead }), t.lines ? h('div', { className: 'wy-doc__meta' }, (docText(t.lines, lead) || []).map(function (ln, k) { return h('div', { key: k }, h('bdi', null, ln)); })) : null),
      h('div', { className: 'wy-doc__title' }, title ? h('h1', { lang: lead }, title[lead]) : null, title && title[o] ? h('div', { className: 'wy-doc__title-alt', lang: o, dir: o === 'ar' ? 'rtl' : 'ltr' }, title[o]) : null, no));
  }
  function docFoot(props, p, n, lead) {
    var ref = props.reference ? h(React.Fragment, null, h(DocLabel, { l: 'reference', lead: lead, single: true }), ' ', h('bdi', { className: 'wy-doc__mono' }, props.reference), ' · ') : null;
    return h('footer', { className: 'wy-doc__foot' },
      h('div', { className: 'wy-doc__foot-start' }, p === 0 && props.stamp ? h('span', { className: 'wy-doc__stamp' }, h(Icon, { name: 'check', size: 11 }), h(DocLabel, { l: props.stamp === true ? 'jofotara' : props.stamp, lead: lead, single: true })) : null,
        p === 0 && props.footNote ? h('div', { className: 'wy-doc__footnote' }, docText(props.footNote, lead)) : null,
        h('div', null, ref, h(DocLabel, { l: 'page', lead: lead, single: true, vars: { p: p + 1, n: n } }))),
      p === 0 && props.qr ? h(DocQR, { src: props.qr.src, label: props.qr.label, lead: lead }) : null);
  }
  function docParties(props, lead) {
    if (!props.parties) return null;
    return h('div', { className: 'wy-doc__parties', style: { '--cols': props.parties.length } }, props.parties.map(function (pt, k) {
      var lines = docText(pt.lines, lead);
      return h('div', { key: k }, h(DocLabel, { l: pt.label, lead: lead, className: 'wy-doc__label' }), h('div', { className: cx('wy-doc__value', pt.mono && 'wy-doc__mono') }, h('bdi', null, docText(pt.value, lead))),
        lines ? h('div', { className: 'wy-doc__meta' }, lines.map(function (ln, j) { return h('div', { key: j }, h('bdi', null, ln)); })) : null); }));
  }
  function docSheet(props, p, n, lead, body) {
    var title = props.title ? docLabel(props.title) : { en: 'Document', ar: 'مستند' };
    return h('section', { key: p, className: cx('wy-doc', 'wy-doc--' + (props.size || 'A4')), dir: lead === 'ar' ? 'rtl' : 'ltr', lang: lead, 'aria-label': title[lead] + ' ' + (p + 1) + '/' + n },
      docHead(props, p, lead), body, docFoot(props, p, n, lead));
  }
  /* `aside` (optional) sits at the start side beside the totals on the last page. */
  /* Free-form pages (letters, reports): the same letterhead, running header and foot, with your content on each page. */
  function DocPages(props) {
    var lead = props.lead || 'en', pages = props.pages || [props.children], n = pages.length;
    return h('div', { className: cx('wy-docs', props.className) }, pages.map(function (body, p) {
      return docSheet(props, p, n, lead, h(React.Fragment, null, p === 0 ? docParties(props, lead) : null, body)); }));
  }
  /* Key numbers for reports: ink only, tabular. */
  function DocKpis(props) {
    var lead = props.lead || 'en';
    return h('div', { className: 'wy-doc__kpis', style: { '--cols': (props.items || []).length } }, (props.items || []).map(function (k, i) {
      return h('div', { key: i, className: 'wy-doc__kpi' }, h(DocLabel, { l: k.label, lead: lead, className: 'wy-doc__label' }), h('div', { className: 'wy-doc__kpi-n' }, h('bdi', null, k.value)), k.note ? h('div', { className: 'wy-doc__meta' }, docText(k.note, lead)) : null); }));
  }
  /* A bar chart for print: grey bars, the one that matters in ink, values on the bars, no legend. */
  function DocBars(props) {
    var lead = props.lead || 'en', data = props.data || [], W = 600, H = props.height || 180, top = 18, bottom = 22;
    var max = Math.max.apply(null, data.map(function (d) { return d.value; }).concat(props.ticks || []).concat([1]));
    var step = W / Math.max(data.length, 1), bw = Math.min(step * 0.56, 64), hi = props.highlight != null ? props.highlight : data.length - 1;
    var ticks = props.ticks || []; var y = function (v) { return top + (H - top - bottom) * (1 - v / max); };
    return h('figure', { className: 'wy-doc__chart' }, props.title ? h(DocLabel, { as: 'figcaption', l: props.title, lead: lead, className: 'wy-doc__label' }) : null,
      h('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': docText(props.alt, lead) || '' },
        ticks.map(function (t) { return h('g', { key: 't' + t }, h('line', { x1: 0, x2: W, y1: y(t), y2: y(t), stroke: '#e0e0e0', strokeWidth: 0.75 }), h('text', { x: lead === 'ar' ? W : 0, y: y(t) - 3, fontSize: 9, fill: '#555', textAnchor: lead === 'ar' ? 'end' : 'start' }, props.tickFormat ? props.tickFormat(t) : t)); }),
        data.map(function (d, i) { var idx = lead === 'ar' ? data.length - 1 - i : i; var x = idx * step + (step - bw) / 2, on = i === hi;
          return h('g', { key: i }, h('rect', { x: x, y: y(d.value), width: bw, height: Math.max(0, H - bottom - y(d.value)), rx: 1.5, fill: on ? '#0a0a0a' : '#b4b4b4' }),
            h('text', { x: x + bw / 2, y: y(d.value) - 4, fontSize: 9.5, fontWeight: on ? 600 : 400, fill: on ? '#0a0a0a' : '#555', textAnchor: 'middle' }, d.display || d.value),
            h('text', { x: x + bw / 2, y: H - 7, fontSize: 9.5, fill: '#555', textAnchor: 'middle' }, docText(d.label, lead))); }),
        h('line', { x1: 0, x2: W, y1: H - bottom, y2: H - bottom, stroke: '#0a0a0a', strokeWidth: 1 })));
  }
  function Doc(props) {
    var lead = props.lead || 'en', o = otherLang(lead), rtl = lead === 'ar';
    var cols = props.columns || [], rows = props.rows || [];
    var cap1 = props.rowsFirst || 14, capN = props.rowsNext || 20, tail = props.tailRows != null ? props.tailRows : 6;
    var chunks = [], i = 0, first = true;
    while (i < rows.length) { var cap = first ? cap1 : capN, rem = rows.length - i;
      var take = rem <= cap - tail ? rem : rem > cap ? cap : rem - 1; if (take < 1) take = rem;
      chunks.push(rows.slice(i, i + take)); i += take; first = false; }
    if (!chunks.length) chunks.push([]);
    var n = chunks.length, carry = props.carry, sum = 0;
    var pages = chunks.map(function (ch, p) {
      var last = p === n - 1, bf = sum;
      if (carry) ch.forEach(function (r) { sum += Number(r[carry]) || 0; });
      var table = cols.length && (ch.length || p > 0) ? h(DocTable, { lead: lead, columns: cols, rows: ch,
        before: p > 0 && carry ? h('tr', { className: 'wy-doc__bf' }, h('td', { colSpan: cols.length - 1 }, h(DocLabel, { l: 'brought', lead: lead, single: true })), h('td', { className: 'wy-doc__n' }, docNum(bf, 3))) : null }) : null;
      var carryLine = !last && carry ? h('div', { className: 'wy-doc__carry' }, h(DocLabel, { l: 'carried', lead: lead }), h('span', { className: 'wy-doc__n' }, docNum(sum, 3))) : null;
      var totals = last && (props.totals || props.grand) ? h('div', { className: 'wy-doc__totals' }, (props.totals || []).map(function (r, k) { return h('div', { key: k }, h(DocLabel, { l: r.label, lead: lead }), h('span', { className: 'wy-doc__n' }, docNum(r.value, 3))); }),
        props.grand ? h('div', { className: 'wy-doc__grand' }, h(DocLabel, { l: props.grand.label || 'total', lead: lead }), h('span', { className: 'wy-doc__n' }, docNum(props.grand.value, 3))) : null) : null;
      var words = last && props.grand && props.words !== false ? h('div', { className: 'wy-doc__words' }, h('span', { lang: lead, dir: rtl ? 'rtl' : 'ltr' }, amountInWords(props.grand.value, lead)), h('span', { lang: o, dir: rtl ? 'ltr' : 'rtl' }, amountInWords(props.grand.value, o))) : null;
      var sum_ = last && props.aside ? h('div', { className: 'wy-doc__sum' }, h('div', { className: 'wy-doc__aside' }, props.aside), h('div', { className: 'wy-doc__sum-end' }, totals, words)) : h(React.Fragment, null, totals, words);
      return docSheet(props, p, n, lead, h(React.Fragment, null, p === 0 ? docParties(props, lead) : null, p === 0 ? props.intro : null, table, carryLine, sum_, last ? props.children : null));
    });
    return h('div', { className: cx('wy-docs', props.className) }, pages);
  }
  /*@DOC-END*/
  /*@COMPS-START*/
  /* ---------- Stage 8 components (inventory P1) ----------
     Every visible string has an English default and can be replaced through `labels` (Arabic interface). */
  function usePopover(init) {
    var st = useState(!!init); var open = st[0], setOpen = st[1]; var ref = React.useRef(null);
    React.useEffect(function () {
      if (!open) return undefined;
      function onDown(e) { if (ref.current && !ref.current.contains(e.target)) setOpen(false); }
      function onKey(e) { if (e.key === 'Escape') setOpen(false); }
      document.addEventListener('mousedown', onDown); document.addEventListener('keydown', onKey);
      return function () { document.removeEventListener('mousedown', onDown); document.removeEventListener('keydown', onKey); };
    }, [open]);
    return { open: open, setOpen: setOpen, ref: ref };
  }
  /* 3.7b: Western digits everywhere; accept Arabic-Indic and Persian digits typed or pasted. */
  function westernDigits(s) { return String(s == null ? '' : s).replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 0x0660); }).replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 0x06F0); }).replace(/٫/g, '.').replace(/٬/g, ','); }
  function fill(s, v) { return String(s).replace(/\{(\w+)\}/g, function (m, k) { return v[k] != null ? v[k] : m; }); }

  /* Foundations */
  function TenantLogo(props) {
    var size = props.size || 24, name = props.name || '', r = Math.round(size * 0.29);
    var mark = props.src ? h('span', { className: 'wy-tlogo__frame', style: { width: size, height: size, borderRadius: r } }, h('img', { src: props.src, alt: '' }))
      : h('span', { className: 'wy-tlogo__tile', 'aria-hidden': 'true', style: { width: size, height: size, borderRadius: r, fontSize: Math.round(size * 0.42), background: props.color, color: props.fg } }, props.initials || initials(name).slice(0, size < 32 ? 1 : 2));
    return h('span', { className: cx('wy-tlogo', props.className), role: props.showName === false ? 'img' : undefined, 'aria-label': props.showName === false ? name : undefined }, mark, props.showName === false ? null : h('span', { className: 'wy-tlogo__name' }, name));
  }
  function Tooltip(props) {
    var id = useId('wy-tip'); var child = props.children;
    var trig = React.isValidElement(child) && !props.decorative ? React.cloneElement(child, { 'aria-describedby': id }) : child;
    return h('span', { className: cx('wy-tip', 'wy-tip--' + (props.side || 'top'), props.open && 'is-open', props.className) }, trig,
      h('span', { role: props.decorative ? undefined : 'tooltip', id: props.decorative ? undefined : id, 'aria-hidden': props.decorative ? 'true' : undefined, className: 'wy-tip__bubble' }, props.label, props.shortcut ? h('kbd', { dir: 'ltr' }, props.shortcut) : null));
  }

  /* Inputs */
  function Textarea(props) {
    var id = useId('wy-ta'); var p = Object.assign({}, props, { _id: props.id || id });
    var st = useState(String(props.defaultValue || '')); var val = props.value != null ? String(props.value) : st[0];
    var rest = omit(props, ['label', 'helperText', 'errorText', 'required', 'className', 'id', 'counter', 'defaultValue', 'value', 'onChange']);
    var note = (props.errorText || props.helperText) ? p._id + '-note' : undefined;
    return Field(p, h('div', { className: 'wy-ta' },
      h('textarea', Object.assign({ rows: 3 }, rest, { id: p._id, className: cx('wy-input', 'wy-textarea', props.errorText && 'wy-input--invalid'), value: val, required: props.required, 'aria-invalid': props.errorText ? true : undefined, 'aria-describedby': note,
        onChange: function (e) { st[1](e.target.value); if (props.onChange) props.onChange(e); } })),
      props.maxLength && props.counter !== false ? h('span', { className: cx('wy-ta__count', val.length >= props.maxLength && 'is-full') }, val.length + ' / ' + props.maxLength) : null));
  }
  function Combobox(props) {
    var id = useId('wy-cbx'); var pop = usePopover(props.defaultOpen);
    var q = useState(props.defaultQuery || ''); var sel = useState(props.defaultValue != null ? props.defaultValue : null); var act = useState(0);
    var opts = props.options || [], query = q[0].toLowerCase();
    var list = opts.filter(function (o) { return !query || (o.label + ' ' + (o.hint || '')).toLowerCase().indexOf(query) >= 0; });
    var cur = opts.filter(function (o) { return o.value === sel[0]; })[0];
    var L = Object.assign({ placeholder: 'Search', empty: 'No matches for “{q}”', loading: 'Searching…' }, props.labels || {});
    function choose(o) { sel[1](o.value); q[1](''); pop.setOpen(false); if (props.onChange) props.onChange(o.value); }
    function onKey(e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); pop.setOpen(true); act[1](Math.min(act[0] + 1, list.length - 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); act[1](Math.max(act[0] - 1, 0)); }
      else if (e.key === 'Enter' && pop.open && list[act[0]]) { e.preventDefault(); choose(list[act[0]]); }
    }
    var p = Object.assign({}, props, { _id: id });
    var panel = props.loading ? h('div', { className: 'wy-cbx__state' }, h('span', { className: 'wy-spinner', 'aria-hidden': 'true' }), L.loading)
      : list.length ? h('ul', { id: id + '-list', role: 'listbox', className: 'wy-menu__list' }, list.map(function (o, i) {
        var on = o.value === sel[0];
        return h('li', { key: o.value, id: id + '-o' + i, role: 'option', 'aria-selected': on, className: cx('wy-menu__item', i === act[0] && 'is-active', on && 'is-selected'),
          onMouseDown: function (e) { e.preventDefault(); choose(o); }, onMouseEnter: function () { act[1](i); } },
          o.icon ? h(Icon, { name: o.icon, size: 16 }) : null, h('span', { className: 'wy-menu__label' }, o.label, o.hint ? h('span', { className: 'wy-menu__hint' }, o.hint) : null), on ? h(Icon, { name: 'check', size: 14, className: 'wy-menu__check' }) : null); }))
      : h('div', { className: 'wy-cbx__state' }, fill(L.empty, { q: q[0] }));
    return Field(p, h('div', { className: 'wy-cbx', ref: pop.ref },
      h('div', { className: 'wy-cbx__control' }, h('input', { id: id, className: cx('wy-input', 'wy-cbx__input'), role: 'combobox', autoComplete: 'off', 'aria-expanded': pop.open, 'aria-controls': id + '-list', 'aria-autocomplete': 'list',
        'aria-activedescendant': pop.open && list[act[0]] ? id + '-o' + act[0] : undefined, placeholder: cur ? cur.label : L.placeholder, value: pop.open ? q[0] : (cur ? cur.label : ''),
        onChange: function (e) { q[1](e.target.value); act[1](0); pop.setOpen(true); if (props.onQuery) props.onQuery(e.target.value); }, onFocus: function () { pop.setOpen(true); }, onKeyDown: onKey }),
        h(Icon, { name: 'sort', size: 14, className: 'wy-cbx__chev' })),
      pop.open ? h('div', { className: 'wy-pop wy-cbx__pop' }, panel, props.footer ? h('div', { className: 'wy-menu__foot' }, props.footer) : null) : null));
  }
  function RadioGroup(props) {
    var name = useId('wy-rg'); var st = useState(props.defaultValue); var v = props.value != null ? props.value : st[0];
    return h('fieldset', { className: cx('wy-radios', props.orientation === 'horizontal' && 'wy-radios--row', props.variant === 'cards' && 'wy-radios--cards', props.className) },
      props.label ? h('legend', { className: 'wy-field__label' }, props.label) : null,
      h('div', { className: 'wy-radios__list' }, (props.options || []).map(function (o) {
        return h('label', { key: o.value, className: cx('wy-radio', o.disabled && 'is-disabled') },
          h('input', { type: 'radio', name: name, value: o.value, checked: v === o.value, disabled: o.disabled, onChange: function () { st[1](o.value); if (props.onChange) props.onChange(o.value); } }),
          h('span', { className: 'wy-radio__dot', 'aria-hidden': 'true' }), h('span', { className: 'wy-radio__text' }, h('span', { className: 'wy-radio__label' }, o.label), o.hint ? h('span', { className: 'wy-radio__hint' }, o.hint) : null));
      })), props.errorText ? h('div', { className: 'wy-field__error' }, props.errorText) : null);
  }
  var MONTHS = { en: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ar: ['كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران', 'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول'] };
  var MONTHS_S = { en: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], ar: MONTHS.ar };
  var WEEKDAYS = { en: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'], ar: ['ح', 'ن', 'ث', 'ر', 'خ', 'ج', 'س'] };
  function formatDate(d, lang) { return d ? d.getDate() + ' ' + MONTHS_S[lang === 'ar' ? 'ar' : 'en'][d.getMonth()] + ' ' + d.getFullYear() : ''; }
  function isoDate(s) { if (!s) return null; if (s instanceof Date) return s; var p = String(s).split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function sameDay(a, b) { return !!(a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()); }
  /* Date and date range. Week starts Saturday (Jordan), Friday and Saturday shown as the weekend; Levantine month names in Arabic; Western digits. */
  function DatePicker(props) {
    var lang = props.lang === 'ar' ? 'ar' : 'en', range = !!props.range, ws = props.weekStart != null ? props.weekStart : 6, weekend = props.weekend || [5, 6];
    var pop = usePopover(props.defaultOpen); var id = useId('wy-dp');
    var dv = props.defaultValue; var st = useState(range ? [isoDate(dv && dv[0]), isoDate(dv && dv[1])] : [isoDate(dv), null]);
    var a = st[0][0], b = st[0][1]; var today = isoDate(props.today) || new Date();
    var view = useState(function () { var base = a || today; return new Date(base.getFullYear(), base.getMonth(), 1); }); var vm = view[0];
    var hov = useState(null);
    var L = Object.assign(lang === 'ar' ? { prev: 'الشهر السابق', next: 'الشهر التالي', one: 'اختر التاريخ', two: 'اختر الفترة', thisMonth: 'هذا الشهر', lastMonth: 'الشهر الماضي', last7: 'آخر 7 أيام', days: '{n} أيام' }
      : { prev: 'Previous month', next: 'Next month', one: 'Choose a date', two: 'Choose dates', thisMonth: 'This month', lastMonth: 'Last month', last7: 'Last 7 days', days: '{n} days' }, props.labels || {});
    function set(v) { st[1](v); if (props.onChange) props.onChange(range ? v : v[0]); }
    function pick(d) { if (!range) { set([d, null]); pop.setOpen(false); return; } if (!a || b) set([d, null]); else set(d < a ? [d, a] : [a, d]); }
    function preset(s, e) { set([s, e]); view[1](new Date(s.getFullYear(), s.getMonth(), 1)); }
    var y = vm.getFullYear(), m = vm.getMonth(), first = new Date(y, m, 1), off = (first.getDay() - ws + 7) % 7, n = new Date(y, m + 1, 0).getDate();
    var cells = []; for (var i = 0; i < off; i++) cells.push(null); for (var d = 1; d <= n; d++) cells.push(new Date(y, m, d));
    var endR = b || (range && a && hov[0] && hov[0] > a ? hov[0] : null);
    var shown = a ? (range ? formatDate(a, lang) + ' – ' + (b ? formatDate(b, lang) : '…') : formatDate(a, lang)) : '';
    var count = range && a && b ? Math.round((b - a) / 864e5) + 1 : null;
    var p = Object.assign({}, props, { _id: id, helperText: props.helperText || (count ? fill(L.days, { n: count }) : null) });
    var t = today;
    var cal = h('div', { className: 'wy-pop wy-cal', role: 'dialog', 'aria-label': typeof props.label === 'string' ? props.label : L.one, dir: lang === 'ar' ? 'rtl' : undefined, lang: lang },
      h('div', { className: 'wy-cal__head' }, h(IconButton, { icon: 'chevron-left', label: L.prev, size: 'sm', onClick: function () { view[1](new Date(y, m - 1, 1)); } }),
        h('span', { className: 'wy-cal__title', 'aria-live': 'polite' }, MONTHS[lang][m] + ' ' + y), h(IconButton, { icon: 'chevron-right', label: L.next, size: 'sm', onClick: function () { view[1](new Date(y, m + 1, 1)); } })),
      h('div', { className: 'wy-cal__grid', role: 'grid', onMouseLeave: function () { hov[1](null); } },
        [0, 1, 2, 3, 4, 5, 6].map(function (k) { var wd = (k + ws) % 7; return h('span', { key: 'w' + k, className: cx('wy-cal__wd', weekend.indexOf(wd) >= 0 && 'is-weekend') }, WEEKDAYS[lang][wd]); }),
        cells.map(function (c, k) {
          if (!c) return h('span', { key: 'e' + k });
          var isA = sameDay(c, a), isE = sameDay(c, endR), sel = isA || sameDay(c, b) || (range && isE);
          var inR = range && a && endR && c > a && c < endR;
          return h('button', { key: k, type: 'button', className: cx('wy-cal__day', sel && 'is-sel', inR && 'is-in', range && endR && isA && 'is-start', range && isE && !isA && 'is-end', sameDay(c, t) && 'is-today', weekend.indexOf(c.getDay()) >= 0 && 'is-weekend'),
            'aria-pressed': sel, 'aria-label': formatDate(c, lang), onClick: function () { pick(c); }, onMouseEnter: function () { hov[1](c); } }, c.getDate());
        })),
      range && props.presets !== false ? h('div', { className: 'wy-cal__presets' },
        h('button', { type: 'button', className: 'wy-chipbtn', onClick: function () { preset(new Date(t.getFullYear(), t.getMonth(), 1), new Date(t.getFullYear(), t.getMonth() + 1, 0)); } }, L.thisMonth),
        h('button', { type: 'button', className: 'wy-chipbtn', onClick: function () { preset(new Date(t.getFullYear(), t.getMonth() - 1, 1), new Date(t.getFullYear(), t.getMonth(), 0)); } }, L.lastMonth),
        h('button', { type: 'button', className: 'wy-chipbtn', onClick: function () { preset(new Date(t.getFullYear(), t.getMonth(), t.getDate() - 6), new Date(t.getFullYear(), t.getMonth(), t.getDate())); } }, L.last7)) : null);
    return Field(p, h('div', { className: 'wy-dp', ref: pop.ref },
      h('button', { type: 'button', id: id, className: cx('wy-input', 'wy-dp__trigger', props.errorText && 'wy-input--invalid'), 'aria-haspopup': 'dialog', 'aria-expanded': pop.open, onClick: function () { pop.setOpen(!pop.open); } },
        h(Icon, { name: 'calendar', size: 16 }), h('span', { className: shown ? 'wy-dp__value' : 'wy-dp__ph', lang: lang }, shown || (range ? L.two : L.one))),
      pop.open ? cal : null));
  }
  /* Money: JOD with 3 decimals (fils), Western digits, the amount in words as the hint. */
  function MoneyInput(props) {
    var dec = props.decimals != null ? props.decimals : 3, cur = props.currency || 'JOD'; var id = useId('wy-mi');
    var st = useState(function () { var dv = props.defaultValue; if (dv == null || dv === '') return ''; var r = westernDigits(dv).replace(/[,\s]/g, ''), x = Number(r); return isNaN(x) || (r.split('.')[1] || '').length > dec ? r : docNum(x, dec); });
    var raw = westernDigits(st[0]).replace(/[,\s]/g, ''); var num = raw === '' ? null : Number(raw);
    var bad = raw !== '' && (isNaN(num) || (raw.split('.')[1] || '').length > dec);
    var L = Object.assign({ decimals: cur + ' has ' + dec + ' decimals ({unit}). Use up to ' + dec + '.', unit: 'fils' }, props.labels || {});
    var err = props.errorText || (bad ? fill(L.decimals, { unit: L.unit }) : null);
    var words = props.showWords && num && !bad ? amountInWords(num, props.wordsLang || 'en') : null;
    var p = Object.assign({}, props, { _id: id, errorText: err, helperText: words || props.helperText });
    return Field(p, h('div', { className: cx('wy-money-in', err && 'is-invalid'), dir: 'ltr' }, h('span', { className: 'wy-money-in__cur' }, cur),
      h('input', { id: id, className: 'wy-money-in__input', inputMode: 'decimal', autoComplete: 'off', value: st[0], placeholder: docNum(0, dec), required: props.required, readOnly: props.readOnly, disabled: props.disabled,
        'aria-invalid': err ? true : undefined, 'aria-describedby': (err || p.helperText) ? id + '-note' : undefined,
        onChange: function (e) { st[1](e.target.value); }, onFocus: function () { st[1](raw); },
        onBlur: function () { if (!bad && raw !== '') st[1](docNum(num, dec)); if (props.onChange) props.onChange(bad ? null : num); } })));
  }
  /* One-time code: 6 boxes, paste fills all, digits only, always left to right. */
  function OtpInput(props) {
    var n = props.length || 6; var id = useId('wy-otp'); var refs = React.useRef([]);
    var st = useState(westernDigits(props.defaultValue || '').replace(/\D/g, '').split('').slice(0, n));
    var vals = []; for (var i = 0; i < n; i++) vals.push(st[0][i] || '');
    var L = Object.assign({ digit: 'Digit {i} of {n}' }, props.labels || {});
    function setAll(v) { st[1](v); var code = v.join(''); if (code.length === n && props.onComplete) props.onComplete(code); }
    function focus(k) { var el = refs.current[k]; if (el) { el.focus(); if (el.select) el.select(); } }
    function onPaste(e) { var t = westernDigits(e.clipboardData.getData('text')).replace(/\D/g, '').slice(0, n); if (!t) return; e.preventDefault(); var v = vals.slice(); for (var k = 0; k < t.length; k++) v[k] = t[k]; setAll(v); focus(Math.min(t.length, n - 1)); }
    return h('div', { className: cx('wy-field', props.className) }, props.label ? h('label', { className: 'wy-field__label', htmlFor: id + '-0' }, props.label) : null,
      h('div', { className: cx('wy-otp', 'wy-otp--start', props.errorText && 'is-invalid'), dir: 'ltr', role: 'group', 'aria-label': typeof props.label === 'string' ? props.label : 'Code' }, vals.map(function (v, k) {
        return h(React.Fragment, { key: k }, n % 2 === 0 && n > 4 && k === n / 2 ? h('span', { className: 'wy-otp__sep', 'aria-hidden': 'true' }) : null,
          h('input', { id: id + '-' + k, ref: function (el) { refs.current[k] = el; }, className: cx('wy-otp__cell', v && 'is-filled'), inputMode: 'numeric', autoComplete: k === 0 ? 'one-time-code' : 'off', maxLength: 1, value: v,
            'aria-label': fill(L.digit, { i: k + 1, n: n }), 'aria-invalid': props.errorText ? true : undefined, onPaste: onPaste, disabled: props.disabled,
            onChange: function (e) { var c = westernDigits(e.target.value).replace(/\D/g, '').slice(-1); var nv = vals.slice(); nv[k] = c; setAll(nv); if (c && k < n - 1) focus(k + 1); },
            onKeyDown: function (e) { if (e.key === 'Backspace' && !vals[k] && k > 0) { e.preventDefault(); var nv = vals.slice(); nv[k - 1] = ''; setAll(nv); focus(k - 1); } else if (e.key === 'ArrowLeft' && k > 0) focus(k - 1); else if (e.key === 'ArrowRight' && k < n - 1) focus(k + 1); } }));
      })), (props.errorText || props.helperText) ? h('div', { className: props.errorText ? 'wy-field__error' : 'wy-field__help', role: props.errorText ? 'alert' : undefined }, props.errorText || props.helperText) : null);
  }
  function SearchField(props) {
    var st = useState(props.defaultValue || ''); var L = Object.assign({ placeholder: 'Search', clear: 'Clear search' }, props.labels || {});
    return h('div', { className: cx('wy-search', props.className), role: 'search' }, h(Icon, { name: 'search', size: 16, className: 'wy-search__icon' }),
      h('input', { className: 'wy-input wy-search__input', type: 'search', placeholder: props.placeholder || L.placeholder, 'aria-label': props.placeholder || L.placeholder, value: st[0],
        onChange: function (e) { st[1](e.target.value); if (props.onChange) props.onChange(e.target.value); }, onKeyDown: function (e) { if (e.key === 'Enter' && props.onSearch) props.onSearch(st[0]); if (e.key === 'Escape') st[1](''); } }),
      st[0] ? h('button', { type: 'button', className: 'wy-search__clear', 'aria-label': L.clear, onClick: function () { st[1](''); if (props.onChange) props.onChange(''); } }, h(Icon, { name: 'close', size: 14 }))
        : props.shortcut !== false ? h('kbd', { className: 'wy-kbd wy-search__kbd', 'aria-hidden': 'true', dir: 'ltr' }, props.shortcut || '⌘K') : null);
  }

  /* Navigation */
  function TenantSwitcher(props) {
    var pop = usePopover(props.defaultOpen); var q = useState(''); var list = props.tenants || [];
    var cs = useState(props.current || (list[0] && list[0].name)); var cur = list.filter(function (t) { return t.name === cs[0]; })[0] || list[0] || { name: '' };
    var L = Object.assign({ search: 'Find a workspace', create: 'Create workspace', label: 'Switch workspace', none: 'No workspace matches' }, props.labels || {});
    var shown = list.filter(function (t) { return !q[0] || t.name.toLowerCase().indexOf(q[0].toLowerCase()) >= 0; });
    return h('div', { className: cx('wy-tswitch', props.className), ref: pop.ref },
      h('button', { type: 'button', className: 'wy-tswitch__btn', 'aria-haspopup': 'listbox', 'aria-expanded': pop.open, 'aria-label': L.label + ': ' + cur.name, onClick: function () { pop.setOpen(!pop.open); } },
        h(TenantLogo, { name: cur.name, src: cur.logo, color: cur.color, fg: cur.fg, size: 24 }), h(Icon, { name: 'sort', size: 14 })),
      pop.open ? h('div', { className: 'wy-pop wy-tswitch__pop' },
        list.length > 4 ? h('label', { className: 'wy-tswitch__search' }, h(Icon, { name: 'search', size: 14 }), h('input', { placeholder: L.search, 'aria-label': L.search, value: q[0], onChange: function (e) { q[1](e.target.value); } })) : null,
        shown.length ? h('ul', { role: 'listbox', 'aria-label': L.label, className: 'wy-menu__list' }, shown.map(function (t) {
          var on = t.name === cur.name;
          return h('li', { key: t.name, role: 'option', 'aria-selected': on, className: cx('wy-menu__item', on && 'is-selected'), onClick: function () { cs[1](t.name); pop.setOpen(false); if (props.onChange) props.onChange(t.name); } },
            h(TenantLogo, { name: t.name, src: t.logo, color: t.color, fg: t.fg, size: 24, showName: false }), h('span', { className: 'wy-menu__label' }, t.name, t.role || t.plan ? h('span', { className: 'wy-menu__hint' }, [t.role, t.plan].filter(Boolean).join(' · ')) : null),
            on ? h(Icon, { name: 'check', size: 14, className: 'wy-menu__check' }) : null); })) : h('div', { className: 'wy-cbx__state' }, L.none),
        props.onCreate !== false ? h('div', { className: 'wy-menu__foot' }, h('button', { type: 'button', className: 'wy-menu__item', onClick: props.onCreate }, h(Icon, { name: 'plus', size: 16 }), h('span', { className: 'wy-menu__label' }, L.create))) : null) : null);
  }
  function Segmented(props) {
    var opts = props.options || []; var st = useState(props.defaultValue != null ? props.defaultValue : opts[0] && opts[0].value); var v = props.value != null ? props.value : st[0];
    return h('div', { className: cx('wy-seg', 'wy-seg--lg', props.className), role: 'radiogroup', 'aria-label': props.label }, opts.map(function (o) {
      var on = o.value === v;
      return h('button', { key: o.value, type: 'button', role: 'radio', 'aria-checked': on, lang: o.lang, 'aria-label': o.iconOnly ? o.label : undefined, title: o.iconOnly ? o.label : undefined, className: cx('wy-seg__opt', on && 'is-on'),
        onClick: function () { st[1](o.value); if (props.onChange) props.onChange(o.value); } }, o.icon ? h(Icon, { name: o.icon, size: 14 }) : null, o.iconOnly ? null : o.label);
    }));
  }
  function LanguageSwitch(props) { return h(Segmented, { label: props.label || 'Language', defaultValue: props.value || 'en', onChange: props.onChange, className: props.className, options: [{ value: 'en', label: 'English', lang: 'en' }, { value: 'ar', label: 'العربية', lang: 'ar' }] }); }
  function ThemeSwitch(props) {
    var L = Object.assign({ label: 'Theme', light: 'Light', dark: 'Dark', system: 'Auto' }, props.labels || {});
    return h(Segmented, { label: L.label, defaultValue: props.value || 'system', onChange: props.onChange, className: props.className, options: [{ value: 'light', label: L.light, icon: 'sun', iconOnly: props.compact }, { value: 'dark', label: L.dark, icon: 'moon', iconOnly: props.compact }, { value: 'system', label: L.system, icon: 'desktop', iconOnly: props.compact }] });
  }

  /* Data display */
  function Avatar(props) {
    var size = props.size || 32, kind = props.kind || 'person';
    var inner = kind === 'agent' ? h(Icon, { name: 'robot', size: Math.round(size * 0.56), stroke: 2 }) : kind === 'system' ? h(Mark, { size: Math.round(size * 0.62), label: '' }) : props.src ? h('img', { src: props.src, alt: '' }) : initials(props.name);
    return h('span', { className: cx('wy-avatar', 'wy-avatar--' + kind, props.className), style: { width: size, height: size, fontSize: Math.max(10, Math.round(size * 0.36)) }, role: 'img', 'aria-label': (props.name || '') + (kind === 'agent' ? ' (agent)' : '') },
      inner, props.status ? h('span', { className: 'wy-avatar__status wy-avatar__status--' + props.status, 'aria-hidden': 'true' }) : null);
  }
  function DescriptionList(props) {
    var L = Object.assign({ copy: 'Copy', copied: 'Copied' }, props.labels || {});
    return h('dl', { className: cx('wy-dl', props.columns === 2 && 'wy-dl--2', props.className) }, (props.items || []).map(function (it, i) {
      return h('div', { key: i, className: 'wy-dl__row' }, h('dt', null, it.label), h('dd', null, h('span', { className: cx('wy-dl__v', it.mono && 'wy-mono') }, it.value == null || it.value === '' ? h('span', { className: 'wy-dl__none' }, props.none || '—') : it.value),
        it.copy ? h(IconButton, { icon: 'copy', label: L.copy + ' ' + (typeof it.label === 'string' ? it.label : ''), size: 'sm', onClick: function () { try { navigator.clipboard.writeText(String(it.copy === true ? it.value : it.copy)); } catch (e) { /* no clipboard */ } } }) : null));
    }));
  }
  var TL_ICON = { create: 'plus', edit: 'edit', send: 'send', approve: 'check', comment: 'mail', pay: 'money', undo: 'undo', error: 'alert', document: 'document', read: 'view', propose: 'edit' };
  /* Record history: people, agents (with who they act for) and the system, newest first, grouped by day. */
  function Timeline(props) {
    return h('ol', { className: cx('wy-tl', props.className), 'aria-label': props.label || 'History' }, (props.items || []).map(function (it, i) {
      if (it.day) return h('li', { key: i, className: 'wy-tl__day' }, it.day);
      return h('li', { key: i, className: cx('wy-tl__item', 'wy-tl__item--' + (it.kind || 'edit'), it.actorKind === 'agent' && 'is-agent', it.pending && 'is-pending') },
        h('span', { className: 'wy-tl__node', 'aria-hidden': 'true' }, h(Icon, { name: it.icon || TL_ICON[it.kind] || 'activity', size: 12 })),
        h('div', { className: 'wy-tl__body' },
          h('div', { className: 'wy-tl__line' }, h(ActorChip, { kind: it.actorKind || 'person', name: it.actor, onBehalfOf: it.onBehalfOf, forLabel: props.forLabel }), h('span', { className: 'wy-tl__text' }, it.text), h('time', { className: 'wy-tl__time' }, it.time)),
          it.changes ? h('ul', { className: 'wy-tl__changes' }, it.changes.map(function (c, k) { return h('li', { key: k }, h('span', { className: 'wy-tl__field' }, c.label), c.before != null ? h('s', null, c.before) : null, c.before != null ? h(Icon, { name: 'arrow-right', size: 12 }) : null, h('span', null, c.after)); })) : null,
          it.note ? h('blockquote', { className: 'wy-tl__note' }, it.note) : null,
          it.undo ? h(UndoBar, it.undo) : null));
    }));
  }

  /* Feedback and layers */
  var TOAST_ICON = { success: 'success', error: 'error', warning: 'alert', info: 'info' };
  /* 7.5c: 10 seconds with Undo; the countdown pauses while the pointer or focus is on it. */
  function Toast(props) {
    var dur = props.duration != null ? props.duration : 10000; var st = useState(true); var v = props.variant || 'default';
    if (!st[0]) return null;
    function close() { st[1](false); if (props.onClose) props.onClose(); }
    return h('div', { className: cx('wy-toast', 'wy-toast--' + v, props.className), role: v === 'error' ? 'alert' : 'status' },
      TOAST_ICON[v] ? h(Icon, { name: TOAST_ICON[v], size: 16, className: 'wy-toast__icon' }) : null,
      h('div', { className: 'wy-toast__body' }, h('div', { className: 'wy-toast__title' }, props.title), props.description ? h('div', { className: 'wy-toast__desc' }, props.description) : null),
      props.action ? h('button', { type: 'button', className: 'wy-toast__action', onClick: function () { if (props.action.onClick) props.action.onClick(); close(); } }, props.action.icon ? h(Icon, { name: props.action.icon, size: 14 }) : null, props.action.label) : null,
      h('button', { type: 'button', className: 'wy-toast__close', 'aria-label': props.closeLabel || 'Dismiss', onClick: close }, h(Icon, { name: 'close', size: 14 })),
      dur ? h('span', { className: 'wy-toast__timer', 'aria-hidden': 'true', style: { animationDuration: dur + 'ms', animationDelay: props.elapsed ? (-props.elapsed) + 'ms' : undefined, animationPlayState: props.paused ? 'paused' : undefined }, onAnimationEnd: close }) : null);
  }
  function ToastRegion(props) { return h('div', { className: cx('wy-toasts', props.inline && 'wy-toasts--inline', props.className), 'aria-live': 'polite' }, props.children); }
  /* Destructive confirm: says what will be lost; the button unlocks only when the name is typed exactly. */
  function ConfirmDialog(props) {
    var st = useState(props.defaultTyped || ''); var need = props.confirmText; var ok = !need || st[0].trim() === need;
    var L = Object.assign({ cancel: 'Cancel', type: 'Type {name} to confirm' }, props.labels || {});
    var parts = L.type.split('{name}');
    return h(Dialog, { inline: props.inline, title: props.title, description: props.description, onClose: props.onCancel || function () {},
      footer: h(React.Fragment, null, h(Button, { variant: 'ghost', onClick: props.onCancel }, L.cancel), h(Button, { variant: props.destructive === false ? 'default' : 'destructive', icon: props.icon, disabled: !ok, onClick: props.onConfirm }, props.confirmLabel || 'Delete')) },
      props.children, need ? h(Input, { label: h('span', null, parts[0], h('b', { className: 'wy-confirm__name' }, need), parts[1] || ''), value: st[0], autoComplete: 'off', spellCheck: false, onChange: function (e) { st[1](e.target.value); } }) : null);
  }
  function Drawer(props) {
    var L = Object.assign({ close: 'Close' }, props.labels || {});
    React.useEffect(function () { if (props.inline || !props.onClose) return undefined; function k(e) { if (e.key === 'Escape') props.onClose(); } document.addEventListener('keydown', k); return function () { document.removeEventListener('keydown', k); }; }, [props.inline, props.onClose]);
    return h('div', { className: cx('wy-drawer', props.inline && 'wy-drawer--inline', props.className), role: 'dialog', 'aria-modal': props.inline ? undefined : 'true', 'aria-label': typeof props.title === 'string' ? props.title : undefined, dir: props.dir },
      props.behind ? h('div', { className: 'wy-drawer__behind', 'aria-hidden': 'true' }, props.behind) : null, h('div', { className: 'wy-drawer__scrim', onClick: props.onClose, 'aria-hidden': 'true' }),
      h('aside', { className: 'wy-drawer__panel', style: props.width ? { width: props.width } : undefined },
        h('header', { className: 'wy-drawer__head' }, h('div', { className: 'wy-drawer__heads' }, props.eyebrow ? h('div', { className: 'wy-drawer__eyebrow' }, props.eyebrow) : null, h('h2', { className: 'wy-drawer__title' }, props.title), props.subtitle ? h('div', { className: 'wy-drawer__sub' }, props.subtitle) : null),
          h('div', { className: 'wy-drawer__acts' }, props.actions || null, h(IconButton, { icon: 'close', label: L.close, onClick: props.onClose }))),
        props.tabs ? h('div', { className: 'wy-drawer__tabs' }, props.tabs) : null,
        h('div', { className: 'wy-drawer__body' }, props.children),
        props.footer ? h('footer', { className: 'wy-drawer__foot' }, props.footer) : null));
  }
  function Menu(props) {
    var pop = usePopover(props.defaultOpen); var act = useState(-1); var id = useId('wy-menu'); var items = props.items || [];
    var idx = items.map(function (it, i) { return it.divider || it.heading || it.disabled ? -1 : i; }).filter(function (i) { return i >= 0; });
    function move(dir) { var k = idx.indexOf(act[0]); var nk = k < 0 ? (dir > 0 ? 0 : idx.length - 1) : (k + dir + idx.length) % idx.length; act[1](idx[nk]); }
    function run(it) { if (it.disabled) return; if (it.onClick) it.onClick(); pop.setOpen(false); }
    function onKey(e) {
      if (!pop.open) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); move(1); } else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
      else if (e.key === 'Home') { e.preventDefault(); act[1](idx[0]); } else if (e.key === 'End') { e.preventDefault(); act[1](idx[idx.length - 1]); }
      else if ((e.key === 'Enter' || e.key === ' ') && items[act[0]]) { e.preventDefault(); run(items[act[0]]); }
    }
    var trig = props.trigger || h(IconButton, { icon: 'more', label: props.label || 'More actions' });
    trig = React.cloneElement(trig, { 'aria-haspopup': 'menu', 'aria-expanded': pop.open, 'aria-controls': pop.open ? id : undefined, onClick: function () { pop.setOpen(!pop.open); act[1](-1); } });
    return h('div', { className: cx('wy-menu', props.className), ref: pop.ref, onKeyDown: onKey }, trig,
      pop.open ? h('div', { className: cx('wy-pop', 'wy-menu__pop', props.align === 'start' ? null : 'is-end') }, h('ul', { id: id, role: 'menu', 'aria-label': props.label, className: 'wy-menu__list' }, items.map(function (it, i) {
        if (it.divider) return h('li', { key: i, role: 'separator', className: 'wy-menu__sep' });
        if (it.heading) return h('li', { key: i, role: 'presentation', className: 'wy-menu__heading' }, it.heading);
        return h('li', { key: i, role: 'menuitem', tabIndex: -1, 'aria-disabled': it.disabled || undefined, className: cx('wy-menu__item', act[0] === i && 'is-active', it.danger && 'is-danger', it.disabled && 'is-disabled'),
          onMouseEnter: function () { act[1](i); }, onClick: function () { run(it); } },
          it.icon ? h(Icon, { name: it.icon, size: 16 }) : h('span', { className: 'wy-menu__noicon' }), h('span', { className: 'wy-menu__label' }, it.label, it.hint ? h('span', { className: 'wy-menu__hint' }, it.hint) : null), it.shortcut ? h('kbd', { className: 'wy-kbd', dir: 'ltr' }, it.shortcut) : null);
      }))) : null);
  }
  function Popover(props) {
    var pop = usePopover(props.defaultOpen);
    return h('div', { className: cx('wy-popover', props.className), ref: pop.ref }, React.cloneElement(props.trigger, { 'aria-expanded': pop.open, 'aria-haspopup': 'dialog', onClick: function () { pop.setOpen(!pop.open); } }),
      pop.open ? h('div', { className: cx('wy-pop', 'wy-popover__panel', props.align === 'start' ? null : 'is-end'), role: 'dialog', 'aria-label': props.label || (typeof props.title === 'string' ? props.title : undefined), style: props.width ? { width: props.width } : undefined },
        props.title ? h('div', { className: 'wy-popover__title' }, props.title) : null, props.children) : null);
  }

  /* Agents */
  /* 7.5b: one at a time, oldest first. Deciding moves to the next; a 10-second toast offers Undo. */
  function ApprovalQueue(props) {
    var items = props.items || []; var cur = useState(0); var dec = useState({}); var last = useState(null);
    var L = Object.assign({ pos: '{i} of {n} waiting', order: 'oldest first', prev: 'Previous', next: 'Next', done: 'All caught up', doneDesc: 'Nothing is waiting for you. New requests from agents appear here.', approved: 'Approved', declined: 'Declined', undo: 'Undo' }, props.labels || {});
    var waiting = items.map(function (_, i) { return i; }).filter(function (i) { return !dec[0][i]; });
    var i = waiting.indexOf(cur[0]) >= 0 ? cur[0] : waiting[0]; var pos = waiting.indexOf(i);
    function decide(k, what) { var d = Object.assign({}, dec[0]); d[k] = what; dec[1](d); last[1]({ k: k, what: what, t: Date.now() });
      var rest = waiting.filter(function (w) { return w !== k; }); var nx = rest.filter(function (w) { return w > k; })[0]; cur[1](nx != null ? nx : rest[0]); if (props.onDecide) props.onDecide(k, what); }
    function undo() { if (!last[0]) return; var d = Object.assign({}, dec[0]); delete d[last[0].k]; dec[1](d); cur[1](last[0].k); last[1](null); }
    return h('section', { className: cx('wy-queue', props.className), 'aria-label': props.label || 'Approvals' },
      i == null ? h(EmptyState, { icon: 'success', title: L.done, description: L.doneDesc }) : h(React.Fragment, null,
        h('div', { className: 'wy-queue__pos' }, h('span', { 'aria-live': 'polite' }, fill(L.pos, { i: pos + 1, n: waiting.length }) + ' · ' + L.order),
          h('span', { className: 'wy-row' }, h(Button, { variant: 'ghost', size: 'sm', icon: 'chevron-left', disabled: pos <= 0, onClick: function () { cur[1](waiting[pos - 1]); } }, L.prev),
            h(Button, { variant: 'ghost', size: 'sm', iconEnd: 'chevron-right', disabled: pos >= waiting.length - 1, onClick: function () { cur[1](waiting[pos + 1]); } }, L.next))),
        h(ActionProposal, Object.assign({ key: i }, items[i], { onApprove: function () { decide(i, 'approved'); }, onReject: function () { decide(i, 'declined'); } })),
        waiting.length > 1 ? h('div', { className: 'wy-queue__stack', 'aria-hidden': 'true' }) : null, waiting.length > 2 ? h('div', { className: 'wy-queue__stack', 'aria-hidden': 'true' }) : null),
      last[0] ? h(ToastRegion, { inline: true }, h(Toast, { key: last[0].t, variant: last[0].what === 'approved' ? 'success' : 'default', title: (last[0].what === 'approved' ? L.approved : L.declined) + ': ' + items[last[0].k].title, action: { label: L.undo, icon: 'undo', onClick: undo } })) : null);
  }
  /* 7.5c: after the toast, Undo stays on the activity item for the tool's undo window. */
  function UndoBar(props) {
    var L = Object.assign({ undo: 'Undo', left: '{t} left to undo', expired: 'Can no longer be undone', undone: 'Undone', by: 'by {who}' }, props.labels || {});
    var st = useState(props.state || 'open'); var s = st[0];
    var pct = props.total ? Math.max(0, Math.min(1, (props.remaining || 0) / props.total)) : null;
    return h('div', { className: cx('wy-undo', 'wy-undo--' + s, props.className), role: 'group', 'aria-label': props.text },
      h(Icon, { name: s === 'undone' ? 'undo' : s === 'expired' ? 'lock' : 'clock', size: 14, className: 'wy-undo__icon' }),
      h('span', { className: 'wy-undo__text' }, props.text ? h('span', { className: 'wy-undo__what' }, props.text) : null, props.text ? ' · ' : null,
        s === 'open' ? fill(L.left, { t: props.remainingLabel || '' }) : s === 'expired' ? L.expired : L.undone + (props.undoneBy ? ' ' + fill(L.by, { who: props.undoneBy }) : '')),
      s === 'open' ? h(Button, { variant: 'outline', size: 'xs', icon: 'undo', onClick: function () { st[1]('undone'); if (props.onUndo) props.onUndo(); } }, L.undo) : null,
      s === 'open' && pct != null ? h('span', { className: 'wy-undo__meter', 'aria-hidden': 'true' }, h('span', { style: { width: (pct * 100) + '%' } })) : null);
  }

  /* Platform */
  /* A rendered document in the app: zoom, print, download and send (the one main action). */
  function DocPreview(props) {
    var z = useState(props.zoom || 0.7);
    var L = Object.assign({ download: 'Download PDF', print: 'Print', send: 'Send', zoomIn: 'Zoom in', zoomOut: 'Zoom out' }, props.labels || {});
    function zoom(d) { z[1](Math.max(0.4, Math.min(1.5, Math.round((z[0] + d) * 10) / 10))); }
    return h('section', { className: cx('wy-docview', props.className), 'aria-label': props.title },
      h('header', { className: 'wy-docview__bar' },
        h('div', { className: 'wy-docview__id' }, h(Icon, { name: props.icon || 'document', size: 16 }), h('div', null, h('div', { className: 'wy-docview__title' }, props.title), props.meta ? h('div', { className: 'wy-docview__meta' }, props.meta) : null)),
        props.status || null,
        h('div', { className: 'wy-docview__zoom' }, h(IconButton, { icon: 'zoom-out', label: L.zoomOut, size: 'sm', onClick: function () { zoom(-0.1); } }), h('span', { className: 'wy-docview__pct' }, Math.round(z[0] * 100) + '%'), h(IconButton, { icon: 'zoom-in', label: L.zoomIn, size: 'sm', onClick: function () { zoom(0.1); } })),
        h('div', { className: 'wy-docview__acts' }, h(Button, { variant: 'outline', size: 'sm', icon: 'print', onClick: props.onPrint }, L.print), h(Button, { variant: 'outline', size: 'sm', icon: 'download', onClick: props.onDownload }, L.download),
          props.onSend !== false ? h(Button, { size: 'sm', icon: 'send', onClick: props.onSend }, L.send) : null)),
      h('div', { className: 'wy-docview__canvas', style: props.height ? { height: props.height } : undefined }, h('div', { className: 'wy-docview__zoomer', style: { zoom: z[0] } }, props.children)));
  }

  /* Tenant colour (decision 2.8): one colour in, the rest derived from its hue in OKLCH. */
  function toOklch(c) {
    var m = /oklch\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)/.exec(c);
    if (m) return [+m[1], +m[2], +m[3]];
    var x = c.replace('#', ''); if (x.length === 3) x = x.replace(/./g, '$&$&');
    var lin = [0, 2, 4].map(function (i) { var v = parseInt(x.substr(i, 2), 16) / 255; return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
    var l = Math.cbrt(0.4122214708 * lin[0] + 0.5363325363 * lin[1] + 0.0514459929 * lin[2]), mm = Math.cbrt(0.2119034982 * lin[0] + 0.6806995451 * lin[1] + 0.1073969566 * lin[2]), s = Math.cbrt(0.0883024619 * lin[0] + 0.2817188376 * lin[1] + 0.6299787005 * lin[2]);
    var L = 0.2104542553 * l + 0.793617785 * mm - 0.0040720468 * s, a = 1.9779984951 * l - 2.428592205 * mm + 0.4505937099 * s, b = 0.0259040371 * l + 0.7827717662 * mm - 0.808675766 * s;
    return [L, Math.sqrt(a * a + b * b), (Math.atan2(b, a) * 180 / Math.PI + 360) % 360];
  }
  function oklchHex(L, C, H) {
    function rgb(c) { var a = c * Math.cos(H * Math.PI / 180), b = c * Math.sin(H * Math.PI / 180);
      var l = Math.pow(L + 0.3963377774 * a + 0.2158037573 * b, 3), m = Math.pow(L - 0.1055613458 * a - 0.0638541728 * b, 3), s = Math.pow(L - 0.0894841775 * a - 1.291485548 * b, 3);
      return [4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s, -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s, -0.0041960863 * l - 0.7034186147 * m + 1.707614701 * s]; }
    var v = rgb(C); while (C > 0 && v.some(function (x) { return x < -0.0001 || x > 1.0001; })) { C -= 0.002; v = rgb(Math.max(C, 0)); }
    return '#' + v.map(function (x) { x = Math.min(1, Math.max(0, x)); x = x <= 0.0031308 ? 12.92 * x : 1.055 * Math.pow(x, 1 / 2.4) - 0.055; return ('0' + Math.round(x * 255).toString(16)).slice(-2); }).join('');
  }
  function relLum(hex) { var x = hex.replace('#', ''); return [0, 2, 4].map(function (i) { var v = parseInt(x.substr(i, 2), 16) / 255; return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }).reduce(function (t, v, i) { return t + v * [0.2126, 0.7152, 0.0722][i]; }, 0); }
  function contrast(a, b) { var x = relLum(a), y = relLum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
  function tenantTheme(color, mode) {
    var o = toOklch(color), C = o[1], H = o[2], main = oklchHex(o[0], C, H);
    var dark = mode === 'dark', c = dark ? oklchHex(0.70, Math.min(C, 0.15), H) : main;
    var fg = dark ? '#171717' : (contrast('#ffffff', c) >= 4.5 ? '#ffffff' : '#171717');
    var tint = dark ? oklchHex(0.30, Math.min(C, 0.06), H) : oklchHex(0.96, Math.min(C, 0.03), H);
    var plane = dark ? oklchHex(0.42, Math.min(C, 0.13), H) : oklchHex(0.30, Math.min(C, 0.10), H);
    return { '--primary': c, '--brand': c, '--ring': c, '--chart-1': c, '--primary-foreground': fg, '--brand-foreground': fg, '--primary-tint': tint, '--primary-tint-foreground': c,
      '--sidebar-primary': tint, '--sidebar-primary-foreground': c, '--doc-accent': main,
      '--plane-primary-rest': '-3px 3px 0 0 ' + plane, '--plane-primary-hover': '-5px 5px 0 0 ' + plane, '--plane-rest': '-3px 3px 0 0 ' + c, '--plane-hover': '-5px 5px 0 0 ' + c };
  }
  /*@COMPS-END*/
  /*@P2-START*/
  /* ---------- P2 (stage 7 inventory, built in 2.1) ---------- */

  /* Actions: a group of outline buttons sharing borders; a split button pairs an action with a menu of its variants. */
  function ButtonGroup(props) {
    return h('div', { className: cx('wy-btngroup', props.size === 'sm' && 'wy-btngroup--sm', props.className), role: 'group', 'aria-label': props.label }, props.children);
  }
  function SplitButton(props) {
    var main = props.variant === 'default'; var L = Object.assign({ more: 'More options' }, props.labels || {});
    var size = props.size || 'default';
    var trig = h('button', { type: 'button', className: cx('wy-btn', main ? 'wy-btn--default' : 'wy-btn--outline', 'wy-btn--size-' + size, 'wy-splitbtn__more'), 'aria-label': L.more, disabled: props.disabled }, h(Icon, { name: 'chevron-down', size: size === 'sm' ? 14 : 16 }));
    return h('div', { className: cx('wy-splitbtn', main && 'wy-splitbtn--main', size === 'sm' && 'wy-splitbtn--sm', props.className), role: 'group', 'aria-label': props.label },
      h('button', { type: 'button', className: cx('wy-btn', main ? 'wy-btn--default' : 'wy-btn--outline', 'wy-btn--size-' + size, 'wy-splitbtn__main'), onClick: props.onClick, disabled: props.disabled },
        props.icon ? h(Icon, { name: props.icon, size: size === 'lg' ? 16 : 14 }) : null, props.children),
      h(Menu, { trigger: trig, items: props.items || [], label: L.more, align: props.align, defaultOpen: props.defaultOpen }));
  }
  /* Toggles for a toolbar: pressed = the tint with text in the main colour (7.3). type 'single' or 'multiple'. */
  function ToggleGroup(props) {
    var opts = props.options || []; var multi = props.type === 'multiple';
    var st = useState(props.defaultValue != null ? props.defaultValue : (multi ? [] : null)); var v = props.value != null ? props.value : st[0];
    function isOn(x) { return multi ? (v || []).indexOf(x) >= 0 : v === x; }
    function flip(x) { var nv = multi ? (isOn(x) ? v.filter(function (y) { return y !== x; }) : (v || []).concat([x])) : (v === x && !props.required ? null : x); st[1](nv); if (props.onChange) props.onChange(nv); }
    return h('div', { className: cx('wy-toggles', props.size === 'sm' && 'wy-toggles--sm', props.className), role: 'group', 'aria-label': props.label }, opts.map(function (o) {
      var b = h('button', { key: o.value, type: 'button', 'aria-pressed': isOn(o.value), disabled: o.disabled, 'aria-label': o.iconOnly ? o.label : undefined, className: cx('wy-toggle', isOn(o.value) && 'is-on', o.iconOnly && 'wy-toggle--icon'), onClick: function () { flip(o.value); } },
        o.icon ? h(Icon, { name: o.icon, size: 16 }) : null, o.iconOnly ? null : o.label);
      return o.iconOnly ? h(Tooltip, { key: o.value, label: o.label, shortcut: o.shortcut }, b) : b;
    }));
  }

  /* Phone input: +962 by default, the national number grouped as it is read, Arabic-Indic digits accepted, stored as E.164. */
  var COUNTRIES = [
    { code: 'JO', dial: '962', name: 'Jordan', ar: 'الأردن', len: [8, 9], mobile: /^7[789]/, group: function (d) { return /^7/.test(d) ? [2, 3, 4] : [1, 3, 4]; }, sample: '79 123 4567' },
    { code: 'SA', dial: '966', name: 'Saudi Arabia', ar: 'السعودية', len: [9], mobile: /^5/, group: [2, 3, 4], sample: '50 123 4567' },
    { code: 'AE', dial: '971', name: 'United Arab Emirates', ar: 'الإمارات', len: [9], mobile: /^5/, group: [2, 3, 4], sample: '50 123 4567' },
    { code: 'PS', dial: '970', name: 'Palestine', ar: 'فلسطين', len: [9], mobile: /^5/, group: [2, 3, 4], sample: '59 123 4567' },
    { code: 'IQ', dial: '964', name: 'Iraq', ar: 'العراق', len: [10], mobile: /^7/, group: [3, 3, 4], sample: '790 123 4567' },
    { code: 'EG', dial: '20', name: 'Egypt', ar: 'مصر', len: [10], mobile: /^1/, group: [3, 3, 4], sample: '100 123 4567' },
    { code: 'LB', dial: '961', name: 'Lebanon', ar: 'لبنان', len: [7, 8], mobile: /^(3|7|8)/, group: [2, 3, 3], sample: '71 123 456' },
    { code: 'KW', dial: '965', name: 'Kuwait', ar: 'الكويت', len: [8], mobile: /^[569]/, group: [4, 4], sample: '5012 3456' },
    { code: 'QA', dial: '974', name: 'Qatar', ar: 'قطر', len: [8], mobile: /^[3567]/, group: [4, 4], sample: '3312 3456' },
    { code: 'BH', dial: '973', name: 'Bahrain', ar: 'البحرين', len: [8], mobile: /^3/, group: [4, 4], sample: '3612 3456' },
    { code: 'OM', dial: '968', name: 'Oman', ar: 'عُمان', len: [8], mobile: /^9/, group: [4, 4], sample: '9212 3456' },
    { code: 'GB', dial: '44', name: 'United Kingdom', ar: 'المملكة المتحدة', len: [10], mobile: /^7/, group: [4, 3, 3], sample: '7400 123 456' },
    { code: 'US', dial: '1', name: 'United States', ar: 'الولايات المتحدة', len: [10], mobile: /^/, group: [3, 3, 4], sample: '201 555 0123' }];
  function groupDigits(d, g) { var out = [], i = 0; g.forEach(function (n, k) { if (i < d.length) { out.push(d.substr(i, k === g.length - 1 ? 99 : n)); i += n; } }); return out.join(' '); }
  function PhoneInput(props) {
    var id = useId('wy-ph'); var lang = props.lang === 'ar' ? 'ar' : 'en';
    var cs = useState(props.defaultCountry || 'JO'); var c = COUNTRIES.filter(function (x) { return x.code === cs[0]; })[0] || COUNTRIES[0];
    var vs = useState(function () { var d = westernDigits(props.defaultValue || '').replace(/\D/g, ''); if (d.indexOf(c.dial) === 0 && d.length > c.len[0]) d = d.slice(c.dial.length); return d.replace(/^0+/, ''); });
    var digits = vs[0]; var L = Object.assign({ country: 'Country code', short: 'This number is too short for {country}.', long: 'This number is too long for {country}.', mobile: 'Use a mobile number for text messages.' }, props.labels || {});
    var name = lang === 'ar' ? c.ar : c.name;
    var err = props.errorText || null;
    var ts = useState(!!props.defaultTouched); var touched = ts[0];
    if (!props.errorText && touched && digits.length) {
      if (digits.length < Math.min.apply(null, c.len)) err = fill(L.short, { country: name });
      else if (digits.length > Math.max.apply(null, c.len)) err = fill(L.long, { country: name });
      else if (props.mobileOnly && !c.mobile.test(digits)) err = L.mobile;
    }
    var p = Object.assign({}, props, { _id: id, errorText: err, helperText: props.helperText });
    return Field(p, h('div', { className: cx('wy-phone', err && 'is-invalid', props.disabled && 'is-disabled'), dir: 'ltr' },
      h('label', { className: 'wy-phone__cc' }, h('span', { className: 'wy-sr' }, L.country),
        h('select', { value: c.code, disabled: props.disabled, onChange: function (e) { cs[1](e.target.value); if (props.onChange) props.onChange(null); } }, COUNTRIES.map(function (x) { return h('option', { key: x.code, value: x.code }, (lang === 'ar' ? x.ar : x.name) + ' +' + x.dial); })),
        h('span', { className: 'wy-phone__dial', 'aria-hidden': 'true' }, '+' + c.dial), h(Icon, { name: 'chevron-down', size: 14 })),
      h('input', { id: id, className: 'wy-phone__input', type: 'tel', inputMode: 'tel', autoComplete: 'tel-national', placeholder: c.sample, value: groupDigits(digits, typeof c.group === 'function' ? c.group(digits) : c.group), disabled: props.disabled, readOnly: props.readOnly,
        'aria-invalid': err ? true : undefined, 'aria-describedby': (err || props.helperText) ? id + '-note' : undefined,
        onChange: function (e) { var d = westernDigits(e.target.value).replace(/\D/g, ''); if (d.indexOf(c.dial) === 0 && d.length > Math.max.apply(null, c.len)) d = d.slice(c.dial.length); vs[1](d.replace(/^0+/, '')); },
        onBlur: function () { ts[1](true); if (props.onChange) props.onChange(digits ? '+' + c.dial + digits : null); } })));
  }

  /* File upload: a dashed drop area (nothing set down yet), then one row per file with progress, done or error. */
  function fileSize(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }
  function FileUpload(props) {
    var L = Object.assign({ drop: 'Drop files here or', browse: 'choose files', limits: '{types} up to {size} each', uploading: 'Uploading {pct}%', done: 'Uploaded', remove: 'Remove {name}', retry: 'Try again', cancel: 'Cancel upload' }, props.labels || {});
    var ds = useState(false); var input = React.useRef(null);
    var files = props.files || [];
    function pick(list) { if (props.onFiles) props.onFiles(Array.prototype.slice.call(list || [])); }
    return h('div', { className: cx('wy-upload', props.className) },
      props.label ? h('div', { className: 'wy-field__label' }, props.label) : null,
      h('div', { className: cx('wy-upload__drop', ds[0] && 'is-over', props.disabled && 'is-disabled'),
        onDragOver: function (e) { e.preventDefault(); ds[1](true); }, onDragLeave: function () { ds[1](false); }, onDrop: function (e) { e.preventDefault(); ds[1](false); pick(e.dataTransfer.files); } },
        h('span', { className: 'wy-upload__icon', 'aria-hidden': 'true' }, h(Icon, { name: 'upload', size: 20 })),
        h('div', { className: 'wy-upload__text' }, h('span', null, L.drop + ' ', h('button', { type: 'button', className: 'wy-upload__browse', disabled: props.disabled, onClick: function () { if (input.current) input.current.click(); } }, L.browse)),
          h('span', { className: 'wy-upload__limits' }, fill(L.limits, { types: props.typesLabel || 'PDF, PNG or JPG', size: props.maxSizeLabel || '10 MB' }))),
        h('input', { ref: input, type: 'file', hidden: true, multiple: props.multiple !== false, accept: props.accept, onChange: function (e) { pick(e.target.files); } })),
      files.length ? h('ul', { className: 'wy-upload__list' }, files.map(function (f) {
        var st = f.status || 'done';
        return h('li', { key: f.name, className: cx('wy-upload__file', 'is-' + st) },
          h('span', { className: 'wy-upload__ficon', 'aria-hidden': 'true' }, h(Icon, { name: st === 'error' ? 'error' : 'document', size: 16 })),
          h('div', { className: 'wy-upload__meta' },
            h('div', { className: 'wy-upload__name' }, h('span', { className: 'wy-upload__fname', dir: 'auto' }, f.name), h('span', { className: 'wy-upload__size', dir: 'ltr' }, fileSize(f.size || 0))),
            st === 'uploading' ? h('div', { className: 'wy-upload__bar', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': f.progress || 0, 'aria-label': f.name }, h('span', { style: { width: (f.progress || 0) + '%' } })) : null,
            h('div', { className: cx('wy-upload__state', st === 'error' && 'is-error'), role: st === 'error' ? 'alert' : undefined }, st === 'uploading' ? fill(L.uploading, { pct: f.progress || 0 }) : st === 'error' ? f.error : L.done)),
          st === 'error' && props.onRetry ? h(Button, { variant: 'ghost', size: 'sm', onClick: function () { props.onRetry(f); } }, L.retry) : null,
          h(IconButton, { icon: 'close', size: 'sm', label: fill(st === 'uploading' ? L.cancel : L.remove, { name: f.name }), onClick: function () { if (props.onRemove) props.onRemove(f); } }));
      })) : null);
  }

  /* Navigation: the command palette (⌘K) finds a page, a record or an action, and can hand a request to an agent. */
  function CommandPalette(props) {
    var L = Object.assign({ placeholder: 'Search pages, records and actions', empty: 'Nothing matches “{q}”', move: 'to move', open: 'to open', close: 'to close', label: 'Command palette' }, props.labels || {});
    var q = useState(props.defaultQuery || ''); var act = useState(0); var inp = React.useRef(null);
    React.useEffect(function () { if (!props.inline && inp.current) inp.current.focus(); }, []);
    var flat = [], groups = (props.groups || []).map(function (g) {
      var its = (g.items || []).filter(function (it) { var s = q[0].trim().toLowerCase(); return g.always || !s || (it.label + ' ' + (it.hint || '') + ' ' + (it.keywords || '')).toLowerCase().indexOf(s) >= 0; });
      its = its.map(function (it) { var i = flat.length; flat.push(it); return { it: it, i: i }; }); return { heading: g.heading, items: its };
    }).filter(function (g) { return g.items.length; });
    var a = Math.min(act[0], Math.max(flat.length - 1, 0));
    function run(it) { if (it && it.onSelect) it.onSelect(q[0]); if (props.onClose) props.onClose(); }
    function onKey(e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); act[1]((a + 1) % Math.max(flat.length, 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); act[1]((a - 1 + flat.length) % Math.max(flat.length, 1)); }
      else if (e.key === 'Enter') { e.preventDefault(); run(flat[a]); }
      else if (e.key === 'Escape' && props.onClose) props.onClose();
    }
    var lid = 'wy-cmd-list';
    return h('div', { className: cx('wy-cmd', props.inline && 'wy-cmd--inline'), role: 'dialog', 'aria-modal': props.inline ? undefined : 'true', 'aria-label': L.label, dir: props.dir },
      h('div', { className: 'wy-cmd__scrim', onClick: props.onClose, 'aria-hidden': 'true' }),
      h('div', { className: 'wy-cmd__panel', onKeyDown: onKey },
        h('div', { className: 'wy-cmd__search' }, h(Icon, { name: 'search', size: 16 }),
          h('input', { ref: inp, className: 'wy-cmd__input', placeholder: L.placeholder, value: q[0], role: 'combobox', 'aria-expanded': true, 'aria-controls': lid, 'aria-activedescendant': flat.length ? 'wy-cmd-' + a : undefined, 'aria-label': L.placeholder,
            onChange: function (e) { q[1](e.target.value); act[1](0); } }),
          h('kbd', { className: 'wy-kbd', dir: 'ltr' }, 'Esc')),
        flat.length ? h('div', { id: lid, role: 'listbox', className: 'wy-cmd__list', 'aria-label': L.label }, groups.map(function (g, gi) {
          return h('div', { key: gi, role: 'group', 'aria-label': g.heading, className: 'wy-cmd__group' }, h('div', { className: 'wy-menu__heading', 'aria-hidden': 'true' }, g.heading),
            g.items.map(function (x) { var it = x.it;
              return h('div', { key: x.i, id: 'wy-cmd-' + x.i, role: 'option', 'aria-selected': a === x.i, className: cx('wy-cmd__item', a === x.i && 'is-active', it.agent && 'is-agent'), onMouseMove: function () { if (a !== x.i) act[1](x.i); }, onClick: function () { run(it); } },
                it.agent ? h(AgentGlyph, null) : h(Icon, { name: it.icon || 'arrow-right', size: 16 }),
                h('span', { className: 'wy-cmd__label' }, it.agent ? fill(it.label, { q: q[0] || '…' }) : it.label, it.hint ? h('span', { className: 'wy-menu__hint' }, it.hint) : null),
                it.shortcut ? h('kbd', { className: 'wy-kbd', dir: 'ltr' }, it.shortcut) : null);
            }));
        })) : h('div', { className: 'wy-cmd__empty' }, fill(L.empty, { q: q[0] })),
        h('div', { className: 'wy-cmd__foot', 'aria-hidden': 'true' },
          h('span', null, h('kbd', { className: 'wy-kbd', dir: 'ltr' }, '↑↓'), ' ' + L.move), h('span', null, h('kbd', { className: 'wy-kbd', dir: 'ltr' }, 'Enter'), ' ' + L.open), h('span', null, h('kbd', { className: 'wy-kbd', dir: 'ltr' }, 'Esc'), ' ' + L.close))));
  }

  /* Stepper: where a person is in a flow of 3–6 steps. Done steps are a check in the main colour; the current step is ringed. */
  function Stepper(props) {
    var steps = props.steps || []; var cur = props.current || 0; var L = Object.assign({ step: 'Step {n} of {total}', done: 'Done', current: 'Current step' }, props.labels || {});
    return h('nav', { className: cx('wy-stepper', props.orientation === 'vertical' && 'wy-stepper--vertical', props.className), 'aria-label': props.label },
      h('div', { className: 'wy-stepper__count' }, fill(L.step, { n: cur + 1, total: steps.length }), ' · ', h('b', null, (steps[cur] || {}).label)),
      h('ol', { className: 'wy-stepper__list' }, steps.map(function (s, i) {
        var st = i < cur ? 'done' : i === cur ? 'current' : 'next';
        return h('li', { key: i, className: cx('wy-stepper__step', 'is-' + st), 'aria-current': st === 'current' ? 'step' : undefined },
          h('span', { className: 'wy-stepper__dot', 'aria-hidden': 'true' }, st === 'done' ? h(Icon, { name: 'check', size: 14 }) : i + 1),
          h('span', { className: 'wy-stepper__text' }, h('span', { className: 'wy-stepper__label' }, s.label, st === 'done' ? h('span', { className: 'wy-sr' }, ' (' + L.done + ')') : null), s.hint ? h('span', { className: 'wy-stepper__hint' }, s.hint) : null));
      })));
  }

  /* Agent run status (stage 7): Running, Waiting for approval, Done, Failed with what to do next. */
  var RUN_ICON = { done: 'success', failed: 'error', waiting: 'clock' };
  function AgentRunStatus(props) {
    var st = props.status || 'running'; var L = Object.assign({ running: 'Running', waiting: 'Waiting for approval', done: 'Done', failed: 'Failed', forLabel: 'for', review: 'Review', retry: 'Try again', stop: 'Stop', of: '{done} of {total}' }, props.labels || {});
    var pg = props.progress; var pct = pg && pg.total ? Math.round(100 * pg.done / pg.total) : null;
    if (props.compact) return h('span', { className: cx('wy-run', 'wy-run--compact', 'is-' + st, props.className), role: 'status' },
      st === 'running' ? h('span', { className: 'wy-spinner wy-run__spin', 'aria-hidden': 'true' }) : h(Icon, { name: RUN_ICON[st], size: 14 }),
      h('span', { className: 'wy-run__agent' }, props.agent), h('span', { className: 'wy-run__title' }, props.title));
    return h('div', { className: cx('wy-run', 'is-' + st, props.className), role: st === 'failed' ? 'alert' : 'status' },
      h('div', { className: 'wy-run__head' },
        h(ActorChip, { kind: 'agent', name: props.agent, onBehalfOf: props.onBehalfOf, forLabel: L.forLabel }),
        h('span', { className: cx('wy-badge', st === 'done' ? 'wy-badge--success' : st === 'failed' ? 'wy-badge--destructive' : st === 'waiting' ? 'wy-badge--info' : 'wy-badge--secondary', 'wy-run__badge') },
          st === 'running' ? h('span', { className: 'wy-spinner wy-run__spin', 'aria-hidden': 'true' }) : h(Icon, { name: RUN_ICON[st], size: 12 }), L[st])),
      h('div', { className: 'wy-run__title' }, props.title),
      st === 'running' && pg ? h('div', { className: 'wy-run__progress' }, h('div', { className: 'wy-run__bar', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': pg.total, 'aria-valuenow': pg.done, 'aria-label': props.title }, h('span', { style: { width: pct + '%' } })), h('span', { className: 'wy-run__of' }, fill(L.of, { done: pg.done, total: pg.total }))) : null,
      props.detail ? h('div', { className: 'wy-run__detail' }, props.detail) : null,
      st === 'failed' && props.next ? h('div', { className: 'wy-run__next' }, props.next) : null,
      h('div', { className: 'wy-run__foot' }, h('span', { className: 'wy-run__time' }, props.time),
        h('div', { className: 'wy-run__acts' },
          st === 'running' && props.onStop ? h(Button, { variant: 'ghost', size: 'sm', onClick: props.onStop }, L.stop) : null,
          st === 'waiting' && props.onReview ? h(Button, { variant: 'outline', size: 'sm', onClick: props.onReview }, L.review) : null,
          st === 'failed' && props.onRetry ? h(Button, { variant: 'outline', size: 'sm', icon: 'sync', onClick: props.onRetry }, L.retry) : null,
          props.actions || null)));
  }

  /* Plan gate: what a plan unlocks, shown where the feature lives. Plan names and prices stay placeholders until decided. */
  function PlanGate(props) {
    var L = Object.assign({ title: '{feature}', on: 'On the {plan} plan', compare: 'Compare plans', upgrade: 'Upgrade to {plan}', ask: 'Ask an owner', askNote: 'Only owners can change the plan.' }, props.labels || {});
    var plan = props.plan || '[plan name]'; var owner = props.canUpgrade !== false;
    var body = h('div', { className: 'wy-gate__card' },
      h('div', { className: 'wy-gate__top' }, h('span', { className: 'wy-gate__icon', 'aria-hidden': 'true' }, h(Icon, { name: 'lock', size: 20 })), h('span', { className: 'wy-gate__plan' }, fill(L.on, { plan: plan }))),
      h('div', { className: 'wy-gate__title' }, fill(L.title, { feature: props.feature, plan: plan })),
      props.description ? h('p', { className: 'wy-gate__desc' }, props.description) : null,
      props.includes ? h('ul', { className: 'wy-gate__list' }, props.includes.map(function (x, i) { return h('li', { key: i }, h(Icon, { name: 'check', size: 14 }), x); })) : null,
      h('div', { className: 'wy-gate__acts' }, owner ? [h(Button, { key: 'u', onClick: props.onUpgrade }, fill(L.upgrade, { plan: plan })), h(Button, { key: 'c', variant: 'outline', onClick: props.onCompare }, L.compare)]
        : [h(Button, { key: 'a', variant: 'outline', icon: 'mail', onClick: props.onAsk }, L.ask), h('span', { key: 'n', className: 'wy-gate__note' }, L.askNote)]),
      props.price ? h('div', { className: 'wy-gate__price' }, props.price) : null);
    if (props.variant === 'inline') return h('div', { className: cx('wy-gate', 'wy-gate--inline', props.className) }, body);
    return h('div', { className: cx('wy-gate', props.className) }, h('div', { className: 'wy-gate__behind', 'aria-hidden': 'true', inert: '' }, props.children), body);
  }

  /* ---------- Charts (palette 2.7; series 1 is always the tenant's main colour) ---------- */
  function niceStep(v) { var raw = Math.max(v, 1e-9) / 5, p = Math.pow(10, Math.floor(Math.log10(raw))), f = raw / p; return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p; }
  function niceMax(v) { if (v <= 0) return 1; var st = niceStep(v); return Math.ceil(v / st) * st; }
  function niceTicks(max) { var st = niceStep(max), t = []; for (var x = 0; x <= max + st / 2; x += st) t.push(+x.toFixed(10)); return t; }
  function compact(n) { var a = Math.abs(n); return a >= 1e6 ? +(n / 1e6).toFixed(1) + 'M' : a >= 1e3 ? +(n / 1e3).toFixed(1) + 'K' : String(+n.toFixed(2)); }
  function useBox(def) { var ref = React.useRef(null); var w = useState(def); React.useEffect(function () { if (!ref.current || typeof ResizeObserver === 'undefined') return undefined; var ro = new ResizeObserver(function (e) { var cw = Math.round(e[0].contentRect.width); if (cw) w[1](cw); }); ro.observe(ref.current); return function () { ro.disconnect(); }; }, []); return [ref, w[0]]; }
  function ChartFrame(props) {
    var L = Object.assign({ table: 'Show as table', chart: 'Show as chart' }, props.labels || {}); var tv = useState(false);
    var series = props.series || [];
    return h('figure', { className: cx('wy-chart', props.className) },
      (props.title || props.subtitle) ? h('figcaption', { className: 'wy-chart__head' }, h('div', null, props.title ? h('div', { className: 'wy-chart__title' }, props.title) : null, props.subtitle ? h('div', { className: 'wy-chart__sub' }, props.subtitle) : null),
        h('button', { type: 'button', className: 'wy-chart__toggle', 'aria-pressed': tv[0], onClick: function () { tv[1](!tv[0]); } }, h(Icon, { name: tv[0] ? 'chart' : 'form', size: 14 }), tv[0] ? L.chart : L.table)) : null,
      series.length > 1 && !tv[0] ? h('ul', { className: 'wy-chart__legend' }, series.map(function (s, i) { return h('li', { key: s.key }, h('span', { className: cx('wy-chart__key', props.kind === 'line' && 'wy-chart__key--line'), style: { background: 'var(--chart-' + (i + 1) + ')' } }), s.label); })) : null,
      tv[0] ? props.table : props.children);
  }
  function ChartTable(cats, series, fmt, catLabel) {
    return h('div', { className: 'wy-chart__tablewrap' }, h('table', { className: 'wy-chart__table' }, h('thead', null, h('tr', null, h('th', { scope: 'col' }, catLabel || ''), series.map(function (s) { return h('th', { key: s.key, scope: 'col', className: 'is-num' }, s.label); }))),
      h('tbody', null, cats.map(function (c, r) { return h('tr', { key: r }, h('th', { scope: 'row' }, c.label), series.map(function (s) { return h('td', { key: s.key, className: 'is-num' }, fmt(c.values[s.key])); })); }))));
  }
  function Tip(props) {
    if (!props.at) return null;
    return h('div', { className: 'wy-chart__tip', style: { left: props.at.x, top: props.at.y }, role: 'presentation' }, h('div', { className: 'wy-chart__tiphead' }, props.at.title),
      props.at.rows.map(function (r, i) { return h('div', { key: i, className: 'wy-chart__tiprow' }, h('span', { className: 'wy-chart__tipkey', style: { background: r.color } }), h('b', null, r.value), h('span', null, r.label)); }));
  }
  /* Bars: ≤24px thick, a 4px rounded data end, square at the baseline, 2px surface gap between grouped bars; one value label (the highest) and a tooltip on every bar. */
  function BarChart(props) {
    var data = props.data || []; var series = props.series || [{ key: 'value', label: props.valueLabel || 'Value' }]; var fmt = props.format || compact; var H = props.height || 220;
    var box = useBox(props.width || 560); var W = box[1]; var tip = useState(null);
    var pad = { t: 20, r: 8, b: 28, l: 40 }; var iw = W - pad.l - pad.r, ih = H - pad.t - pad.b;
    var cats = data.map(function (d) { var v = {}; series.forEach(function (s) { v[s.key] = d[s.key]; }); return { label: d.label, values: v }; });
    var max = niceMax(Math.max.apply(null, data.map(function (d) { return Math.max.apply(null, series.map(function (s) { return d[s.key] || 0; })); }).concat([0])));
    var ticks = niceTicks(max);
    var band = iw / Math.max(data.length, 1); var n = series.length; var bw = Math.min(24, (band * 0.6 - 2 * (n - 1)) / n); var gw = bw * n + 2 * (n - 1);
    var top = props.highlight != null ? props.highlight : data.reduce(function (b, d, i) { return (d[series[0].key] || 0) > (data[b] ? data[b][series[0].key] || 0 : -1) ? i : b; }, 0);
    function y(v) { return pad.t + ih - (v / max) * ih; }
    function barPath(x, v) { var yy = y(v), base = pad.t + ih, r = Math.min(4, bw / 2, base - yy); return 'M' + x + ',' + base + 'V' + (yy + r) + 'Q' + x + ',' + yy + ' ' + (x + r) + ',' + yy + 'H' + (x + bw - r) + 'Q' + (x + bw) + ',' + yy + ' ' + (x + bw) + ',' + (yy + r) + 'V' + base + 'Z'; }
    return h(ChartFrame, { title: props.title, subtitle: props.subtitle, series: series, labels: props.labels, table: ChartTable(cats, series, fmt, props.categoryLabel) },
      h('div', { className: 'wy-chart__plot', ref: box[0], dir: 'ltr', onMouseLeave: function () { tip[1](null); } },
        h('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': props.title },
          ticks.map(function (t, i) { return h('g', { key: i }, h('line', { x1: pad.l, x2: W - pad.r, y1: y(t), y2: y(t), className: i ? 'wy-chart__grid' : 'wy-chart__base' }), h('text', { x: pad.l - 8, y: y(t) + 4, textAnchor: 'end', className: 'wy-chart__tick' }, compact(t))); }),
          data.map(function (d, i) {
            var x0 = pad.l + band * i + (band - gw) / 2;
            return h('g', { key: i, tabIndex: 0, className: 'wy-chart__hit', 'aria-label': d.label + ': ' + series.map(function (s) { return s.label + ' ' + fmt(d[s.key]); }).join(', '),
              onMouseMove: function (e) { var r = e.currentTarget.ownerSVGElement.getBoundingClientRect(); tip[1]({ x: e.clientX - r.left, y: e.clientY - r.top, title: d.label, rows: series.map(function (s, k) { return { color: 'var(--chart-' + (k + 1) + ')', value: fmt(d[s.key]), label: s.label }; }) }); },
              onFocus: function () { tip[1]({ x: x0 + gw / 2, y: y(d[series[0].key] || 0), title: d.label, rows: series.map(function (s, k) { return { color: 'var(--chart-' + (k + 1) + ')', value: fmt(d[s.key]), label: s.label }; }) }); }, onBlur: function () { tip[1](null); } },
              h('rect', { x: pad.l + band * i, y: pad.t, width: band, height: ih, fill: 'transparent' }),
              series.map(function (s, k) { var v = d[s.key] || 0; return v > 0 ? h('path', { key: s.key, d: barPath(x0 + k * (bw + 2), v), style: { fill: 'var(--chart-' + (k + 1) + ')' }, className: 'wy-chart__bar' }) : null; }),
              i === top && props.label !== false ? h('text', { x: x0 + bw / 2, y: y(d[series[0].key] || 0) - 6, textAnchor: 'middle', className: 'wy-chart__val' }, fmt(d[series[0].key])) : null,
              h('text', { x: pad.l + band * i + band / 2, y: H - 8, textAnchor: 'middle', className: 'wy-chart__tick' }, d.label));
          })),
        h(Tip, { at: tip[0] })));
  }
  /* Lines: 2px, round joins, an 8px end dot with a 2px surface ring, the end value labelled; a crosshair snaps to the nearest point and lists every series. */
  function LineChart(props) {
    var data = props.data || []; var series = props.series || [{ key: 'value', label: props.valueLabel || 'Value' }]; var fmt = props.format || compact; var H = props.height || 220;
    var box = useBox(props.width || 560); var W = box[1]; var hv = useState(null);
    var pad = { t: 16, r: 56, b: 28, l: 40 }; var iw = W - pad.l - pad.r, ih = H - pad.t - pad.b;
    var cats = data.map(function (d) { var v = {}; series.forEach(function (s) { v[s.key] = d[s.key]; }); return { label: d.label, values: v }; });
    var max = niceMax(Math.max.apply(null, data.map(function (d) { return Math.max.apply(null, series.map(function (s) { return d[s.key] || 0; })); }).concat([0])));
    var ticks = niceTicks(max);
    function x(i) { return pad.l + (data.length > 1 ? iw * i / (data.length - 1) : iw / 2); } function y(v) { return pad.t + ih - (v / max) * ih; }
    var every = Math.ceil(data.length / Math.max(1, Math.floor(iw / 56)));
    var last = data.length - 1; var hi = hv[0];
    function at(i) { return { x: x(i), y: pad.t, title: data[i].label, rows: series.map(function (s, k) { return { color: 'var(--chart-' + (k + 1) + ')', value: fmt(data[i][s.key]), label: s.label }; }) }; }
    return h(ChartFrame, { title: props.title, subtitle: props.subtitle, series: series, kind: 'line', labels: props.labels, table: ChartTable(cats, series, fmt, props.categoryLabel) },
      h('div', { className: 'wy-chart__plot', ref: box[0], dir: 'ltr', tabIndex: 0, 'aria-label': props.title,
        onMouseMove: function (e) { var r = e.currentTarget.getBoundingClientRect(); var px = e.clientX - r.left; var i = Math.round((px - pad.l) / (iw / Math.max(last, 1))); hv[1](Math.max(0, Math.min(last, i))); },
        onMouseLeave: function () { hv[1](null); },
        onKeyDown: function (e) { if (e.key === 'ArrowRight') hv[1](hi == null ? 0 : Math.min(last, hi + 1)); else if (e.key === 'ArrowLeft') hv[1](hi == null ? last : Math.max(0, hi - 1)); }, onBlur: function () { hv[1](null); } },
        h('svg', { width: W, height: H, viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': props.title },
          ticks.map(function (t, i) { return h('g', { key: i }, h('line', { x1: pad.l, x2: W - pad.r, y1: y(t), y2: y(t), className: i ? 'wy-chart__grid' : 'wy-chart__base' }), h('text', { x: pad.l - 8, y: y(t) + 4, textAnchor: 'end', className: 'wy-chart__tick' }, compact(t))); }),
          data.map(function (d, i) { return i % every === 0 || i === last ? h('text', { key: i, x: x(i), y: H - 8, textAnchor: 'middle', className: 'wy-chart__tick' }, d.label) : null; }),
          props.area && series.length === 1 ? h('path', { className: 'wy-chart__area', style: { fill: 'var(--chart-1)' }, d: 'M' + x(0) + ',' + y(0) + data.map(function (d, i) { return 'L' + x(i) + ',' + y(d[series[0].key] || 0); }).join('') + 'L' + x(last) + ',' + y(0) + 'Z' }) : null,
          series.map(function (s, k) { return h('path', { key: s.key, className: 'wy-chart__line', style: { stroke: 'var(--chart-' + (k + 1) + ')' }, d: data.map(function (d, i) { return (i ? 'L' : 'M') + x(i) + ',' + y(d[s.key] || 0); }).join('') }); }),
          hi != null ? h('line', { className: 'wy-chart__cross', x1: x(hi), x2: x(hi), y1: pad.t, y2: pad.t + ih }) : null,
          series.map(function (s, k) { var i = hi != null ? hi : last; return h('circle', { key: s.key, cx: x(i), cy: y(data[i] ? data[i][s.key] || 0 : 0), r: 4, className: 'wy-chart__dot', style: { fill: 'var(--chart-' + (k + 1) + ')' } }); }),
          hi == null && props.endLabels !== false ? series.map(function (s, k) { return h('text', { key: s.key, x: x(last) + 10, y: y(data[last] ? data[last][s.key] || 0 : 0) + 4, className: 'wy-chart__val' }, fmt(data[last][s.key])); }) : null),
        h(Tip, { at: hi != null ? at(hi) : null })));
  }
  /* Donut: up to 6 slices (the rest fold into "Other"), 2px surface gaps, the total in the middle, values in the legend. */
  function DonutChart(props) {
    var L = Object.assign({ other: 'Other', total: 'Total' }, props.labels || {}); var fmt = props.format || compact; var size = props.size || 176; var hv = useState(null);
    var d = (props.data || []).slice().sort(function (a, b) { return props.sort === false ? 0 : b.value - a.value; });
    if (d.length > 6) { var rest = d.slice(5).reduce(function (t, x) { return t + x.value; }, 0); d = d.slice(0, 5).concat([{ label: L.other, value: rest, other: true }]); }
    var tot = d.reduce(function (t, x) { return t + x.value; }, 0) || 1; var R = size / 2, r = R - 22, c = R; var a0 = -Math.PI / 2;
    function pt(a, rr) { return [c + rr * Math.cos(a), c + rr * Math.sin(a)]; }
    var arcs = d.map(function (x, i) { var a = (x.value / tot) * Math.PI * 2; var s = a0, e = a0 + a; a0 = e; var gap = d.length > 1 ? Math.min(0.02, a / 4) : 0;
      var p1 = pt(s + gap, R), p2 = pt(e - gap, R), p3 = pt(e - gap * R / r, r), p4 = pt(s + gap * R / r, r); var big = a - 2 * gap > Math.PI ? 1 : 0;
      return { x: x, i: i, color: x.other ? 'var(--chart-6)' : 'var(--chart-' + (i + 1) + ')', d: d.length === 1 ? null : 'M' + p1 + 'A' + R + ',' + R + ' 0 ' + big + ' 1 ' + p2 + 'L' + p3 + 'A' + r + ',' + r + ' 0 ' + big + ' 0 ' + p4 + 'Z' }; });
    var cats = d.map(function (x) { return { label: x.label, values: { value: x.value, share: Math.round(100 * x.value / tot) + '%' } }; });
    var hx = hv[0] != null ? arcs[hv[0]] : null;
    return h(ChartFrame, { title: props.title, subtitle: props.subtitle, labels: props.labels, table: ChartTable(cats, [{ key: 'value', label: props.valueLabel || 'Value' }, { key: 'share', label: '%' }], function (v) { return typeof v === 'number' ? fmt(v) : v; }, props.categoryLabel) },
      h('div', { className: 'wy-donut' },
        h('svg', { width: size, height: size, viewBox: '0 0 ' + size + ' ' + size, role: 'img', 'aria-label': props.title, className: 'wy-donut__svg' },
          arcs.map(function (a) { return a.d ? h('path', { key: a.i, d: a.d, style: { fill: a.color }, className: cx('wy-chart__slice', hv[0] === a.i && 'is-hover'), tabIndex: 0, 'aria-label': a.x.label + ': ' + fmt(a.x.value),
            onMouseEnter: function () { hv[1](a.i); }, onMouseLeave: function () { hv[1](null); }, onFocus: function () { hv[1](a.i); }, onBlur: function () { hv[1](null); } }) : h('circle', { key: a.i, cx: c, cy: c, r: (R + r) / 2, style: { stroke: a.color, strokeWidth: R - r, fill: 'none' } }); }),
          h('text', { x: c, y: c - 2, textAnchor: 'middle', className: 'wy-donut__total' }, fmt(hx ? hx.x.value : tot)),
          h('text', { x: c, y: c + 16, textAnchor: 'middle', className: 'wy-donut__cap' }, hx ? Math.round(100 * hx.x.value / tot) + '%' : props.totalLabel || L.total)),
        h('ul', { className: 'wy-donut__legend' }, arcs.map(function (a) { return h('li', { key: a.i, className: hv[0] === a.i ? 'is-hover' : undefined, onMouseEnter: function () { hv[1](a.i); }, onMouseLeave: function () { hv[1](null); } },
          h('span', { className: 'wy-chart__key', style: { background: a.color } }), h('span', { className: 'wy-donut__name' }, a.x.label), h('b', { className: 'wy-donut__v' }, fmt(a.x.value)), h('span', { className: 'wy-donut__pct' }, Math.round(100 * a.x.value / tot) + '%')); }))));
  }
  /*@P2-END*/

  window.Whity = Object.assign(window.Whity || {}, {
    Mark: Mark, Lockup: Lockup, BuiltOnWhity: BuiltOnWhity, Icon: Icon, Blueprint: Blueprint, cutFor: cutFor, iconNames: Object.keys(ICONS),
    Button: Button, IconButton: IconButton, Badge: Badge, Money: Money, Skeleton: Skeleton, Card: Card, Dialog: Dialog, EmptyState: EmptyState,
    Input: Input, Select: Select, Switch: Switch, Checkbox: Checkbox, Tabs: Tabs, Breadcrumb: Breadcrumb, Pagination: Pagination, PageHeader: PageHeader, AppShell: AppShell,
    DataTable: DataTable, PluginCard: PluginCard, Alert: Alert, ActorChip: ActorChip, ActionProposal: ActionProposal, AgentActivity: AgentActivity, ScopeGrant: ScopeGrant,
    TenantLogo: TenantLogo, Tooltip: Tooltip, Textarea: Textarea, Combobox: Combobox, RadioGroup: RadioGroup, DatePicker: DatePicker, MoneyInput: MoneyInput, OtpInput: OtpInput, SearchField: SearchField, TenantSwitcher: TenantSwitcher, Segmented: Segmented, LanguageSwitch: LanguageSwitch, ThemeSwitch: ThemeSwitch, Avatar: Avatar, DescriptionList: DescriptionList, Timeline: Timeline, Toast: Toast, ToastRegion: ToastRegion, ConfirmDialog: ConfirmDialog, Drawer: Drawer, Menu: Menu, Popover: Popover, ApprovalQueue: ApprovalQueue, UndoBar: UndoBar, DocPreview: DocPreview, westernDigits: westernDigits, tenantTheme: tenantTheme, contrast: contrast, formatDate: formatDate,
    ButtonGroup: ButtonGroup, SplitButton: SplitButton, ToggleGroup: ToggleGroup, PhoneInput: PhoneInput, FileUpload: FileUpload, CommandPalette: CommandPalette, Stepper: Stepper, AgentRunStatus: AgentRunStatus, PlanGate: PlanGate, BarChart: BarChart, LineChart: LineChart, DonutChart: DonutChart, phoneCountries: COUNTRIES,
    Doc: Doc, DocLabel: DocLabel, DocSign: DocSign, DocNote: DocNote, DocAmount: DocAmount, DocTable: DocTable, DocPages: DocPages, DocKpis: DocKpis, DocBars: DocBars, amountInWords: amountInWords, docLabels: DOC_L
  });
})();
