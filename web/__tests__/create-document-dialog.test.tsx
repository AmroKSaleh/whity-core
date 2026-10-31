import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * New document (editor M4c): the dialog only chooses the published template;
 * issuing happens in the editor. So these assert what is offered, that a
 * choice hands the template back, and the two honest empty states.
 */

import { CreateDocumentDialog, type CreatableTemplate } from '@/components/documents/create-document-dialog';

const INVOICE: CreatableTemplate = { id: 5, title: 'Example Bakery invoice', recordType: 'invoice', recordTypeLabel: 'Invoice' };
const NOTICE: CreatableTemplate = { id: 7, title: 'Example Bakery notice', recordType: null };

function mount(overrides: Partial<React.ComponentProps<typeof CreateDocumentDialog>> = {}) {
  const onChoose = jest.fn();
  render(
    <CreateDocumentDialog
      open
      onOpenChange={jest.fn()}
      templates={[INVOICE, NOTICE]}
      templatesLoading={false}
      templatesError={null}
      onChoose={onChoose}
      {...overrides}
    />
  );
  return { onChoose };
}

it('lists the published templates with what each is for', () => {
  mount();

  expect(screen.getByRole('button', { name: /Example Bakery invoice/ })).toHaveTextContent('Record: Invoice');
  expect(screen.getByRole('button', { name: /Example Bakery notice/ })).toHaveTextContent('No record needed');
});

it('hands the chosen template back, to open in the editor', async () => {
  const { onChoose } = mount();

  await userEvent.click(screen.getByRole('button', { name: /Example Bakery invoice/ }));

  expect(onChoose).toHaveBeenCalledWith(INVOICE);
});

it('asks for nothing else: no record, field or title inputs', () => {
  mount();

  expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
  expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
});

it('marks a tenant title as bidi content', () => {
  mount();

  expect(screen.getByText('Example Bakery invoice')).toHaveAttribute('dir', 'auto');
});

it('states that no published templates are available rather than showing an empty list', () => {
  mount({ templates: [] });

  expect(screen.getByText(/no templates available/i)).toBeInTheDocument();
  expect(screen.getByText(/issued from a published template/i)).toBeInTheDocument();
  expect(screen.queryByRole('list')).not.toBeInTheDocument();
});

it('tells a template load FAILURE apart from having no templates', () => {
  mount({ templates: [], templatesError: 'Failed to load the full template list' });

  expect(screen.getByText('Failed to load the full template list')).toBeInTheDocument();
  expect(screen.queryByText(/no templates available/i)).not.toBeInTheDocument();
});
