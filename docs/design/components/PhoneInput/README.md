# PhoneInput

A phone number with its country code in the same 36px control. Jordan (+962) is the default; the list covers the region, the UK and the US. The national number is grouped the way it is read (79 123 4567), a leading 0 is dropped, Arabic-Indic digits are accepted (3.7b), and `onChange` gives E.164 (`+962791234567`) when the field is left.

**Props:** `label`, `defaultCountry` (ISO code), `defaultValue`, `mobileOnly` (for text messages and WhatsApp), `lang` (`ar` names the countries in Arabic), `labels`, `helperText`, `errorText`, `disabled`, `readOnly`, `onChange`. `Whity.phoneCountries` lists the codes, lengths and mobile prefixes.

The control stays left to right in Arabic, like every number. Errors appear after the field is left, never while typing.
