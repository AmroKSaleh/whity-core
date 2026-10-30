/**
 * Editor M4b: Print labels (E.43). A label sheet prints one label per record
 * picked, or per row of a CSV whose columns are matched to the template's
 * fields; the dialog says how many printed and how many were cut off.
 */
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';

import { PrintLabelsDialog, csvHeader, guessField } from '@whity-org/features/document-editor';
import type { FieldGroup } from '@whity-org/ui/editor/fields';

// jsdom has no Blob#text(); browsers do.
if (!Blob.prototype.text) {
  Blob.prototype.text = function text(this: Blob) {
    return new Promise<string>((resolve) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result));
      reader.readAsText(this);
    });
  };
}

const FIELDS: FieldGroup[] = [
  { group: 'Company', items: [{ path: 'company.name', label: 'Company name' }] },
  {
    group: 'This template',
    items: [
      { path: 'template.ship_to', label: 'Ship to' },
      { path: 'template.address', label: 'Address' },
    ],
  },
];

describe('csvHeader', () => {
  it('reads the first row with commas, semicolons or tabs, quotes and a byte order mark', () => {
    expect(csvHeader('Name,City\nA,B')).toEqual(['Name', 'City']);
    expect(csvHeader('﻿Name;"Street; number";City\r\nA;B;C')).toEqual(['Name', 'Street; number', 'City']);
    expect(csvHeader('Name\tCity')).toEqual(['Name', 'City']);
    expect(csvHeader('"Say ""hi""",X')).toEqual(['Say "hi"', 'X']);
    expect(csvHeader('')).toEqual([]);
  });
});

describe('guessField', () => {
  it('matches a column to a field by label or by the last part of its path', () => {
    expect(guessField('Ship to', FIELDS)).toBe('template.ship_to');
    expect(guessField('ADDRESS', FIELDS)).toBe('template.address');
    expect(guessField('ship_to', FIELDS)).toBe('template.ship_to');
    expect(guessField('Phone', FIELDS)).toBeNull();
  });
});

describe('PrintLabelsDialog', () => {
  it('prints a CSV with the columns it could match, and says what printed', async () => {
    const onPrint = jest.fn(async () => ({ count: 2, clipped: 1 }));
    const { container } = render(
      <PrintLabelsDialog open onOpenChange={() => {}} fields={FIELDS} findRecords={async () => []} canUseRecords={false} onPrint={onPrint} />
    );
    const print = screen.getByRole('button', { name: 'Print' });
    expect(print).toBeDisabled();

    const file = new File(['Ship to,Address,Phone\nExample Clinic,Rainbow Street 21,0790000000\n'], 'list.csv', { type: 'text/csv' });
    const input = document.body.querySelector('input[type="file"]') as HTMLInputElement;
    await act(async () => {
      fireEvent.change(input, { target: { files: [file] } });
    });
    await screen.findByText('Match each column to a field');
    expect(screen.getByText('The file is used for this print only and isn’t kept.')).toBeInTheDocument();

    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Print' }));
    });
    expect(onPrint).toHaveBeenCalledWith({
      kind: 'csv',
      file,
      columns: { 'Ship to': 'template.ship_to', Address: 'template.address' },
    });
    await screen.findByText('2 labels printed. 1 didn’t fit their label and were cut off.');
    expect(container).toBeTruthy();
  });

  it('prints the records picked, and shows the server\'s reason when refused', async () => {
    const onPrint = jest.fn(async () => {
      throw new Error('Required fields have no value on some labels');
    });
    render(
      <PrintLabelsDialog
        open
        onOpenChange={() => {}}
        fields={FIELDS}
        findRecords={async () => [
          { ref: 'document:3', label: 'Receipt R-17' },
          { ref: 'document:4', label: 'Receipt R-18' },
        ]}
        canUseRecords
        onPrint={onPrint}
      />
    );
    const first = await screen.findByText('Receipt R-17', {}, { timeout: 2000 });
    fireEvent.click(first);
    await waitFor(() => expect(screen.getByText('1 record selected')).toBeInTheDocument());

    await act(async () => {
      fireEvent.click(screen.getByRole('button', { name: 'Print' }));
    });
    expect(onPrint).toHaveBeenCalledWith({ kind: 'records', records: [{ type: 'document', id: '3' }] });
    expect(await screen.findByRole('alert')).toHaveTextContent('Required fields have no value on some labels');
  });
});
