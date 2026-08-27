/**
 * A `file` field on a form is an UPLOAD, not a text box.
 *
 * Before this branch existed, `file` fell through to the shared renderer's
 * `<Input type="text">`, so a form could ask "upload your paper" and offer a
 * place to type a storage key nobody outside the server could obtain. These
 * tests hold the three properties that make the field real:
 *
 *   1. it renders a FILE input, and picking a file uploads it;
 *   2. the answer it holds is the server's REFERENCE, never the File object or
 *      the file's name — that reference is what the submission carries;
 *   3. a failed upload CLEARS the answer and says the server's own sentence,
 *      rather than leaving a stale reference behind an error message. That is
 *      the difference between submitting nothing and silently submitting the
 *      file somebody just replaced.
 *
 * The uploader is injected, because it is injected in production too: the two
 * fill pages post to different endpoints (`/forms/{id}/uploads` and
 * `/public/forms/{slug}/uploads`) and the field must not know which surface it
 * is on.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import {
  FormField,
  type Answer,
  type FormFieldSpec,
  type UploadedFileRef,
} from '@/components/forms/form-fields';

function fileFieldSpec(overrides: Partial<FormFieldSpec> = {}): FormFieldSpec {
  return {
    field_key: 'paper',
    field_type: 'file',
    label: { en: 'Published paper' },
    help_text: null,
    is_required: true,
    options: [],
    multi_valued: false,
    position: 0,
    ...overrides,
  };
}

function storedPdf(overrides: Partial<UploadedFileRef> = {}): UploadedFileRef {
  return {
    reference: 'tenants/1/form-uploads/7/deadbeefdeadbeefdeadbeefdeadbeef.pdf',
    filename: 'paper.pdf',
    content_type: 'application/pdf',
    byte_size: 2048,
    checksum_sha256: 'a'.repeat(64),
    ...overrides,
  };
}

/** A real File, so the input's change event carries what a browser would send. */
function pdfFile(name = 'paper.pdf'): File {
  return new File(['%PDF-1.7 not really a pdf'], name, { type: 'application/pdf' });
}

describe('the file field', () => {
  it('renders a file input rather than a text box', () => {
    render(
      <FormField
        field={fileFieldSpec()}
        value={undefined}
        preferArabic={false}
        onChange={jest.fn()}
        upload={jest.fn()}
      />
    );

    const input = screen.getByLabelText(/Published paper/) as HTMLInputElement;
    expect(input.type).toBe('file');
  });

  it('uploads the picked file and answers with the returned reference', async () => {
    const onChange = jest.fn<void, [Answer]>();
    const upload = jest.fn<Promise<UploadedFileRef>, [File]>().mockResolvedValue(storedPdf());

    render(
      <FormField
        field={fileFieldSpec()}
        value={undefined}
        preferArabic={false}
        onChange={onChange}
        upload={upload}
      />
    );

    const file = pdfFile();
    await userEvent.upload(screen.getByLabelText(/Published paper/), file);

    await waitFor(() => expect(upload).toHaveBeenCalledTimes(1));
    expect(upload.mock.calls[0][0]).toBe(file);

    // THE REFERENCE, not the file and not its name. This is the whole contract
    // between the field and the submission.
    await waitFor(() =>
      expect(onChange).toHaveBeenCalledWith(
        'tenants/1/form-uploads/7/deadbeefdeadbeefdeadbeefdeadbeef.pdf'
      )
    );
  });

  it('shows what is attached, so an empty-looking input is not mistaken for no file', async () => {
    const upload = jest
      .fn<Promise<UploadedFileRef>, [File]>()
      .mockResolvedValue(storedPdf({ filename: 'smith-2026.pdf', byte_size: 3 * 1024 * 1024 }));

    render(
      <FormField
        field={fileFieldSpec()}
        value={undefined}
        preferArabic={false}
        onChange={jest.fn()}
        upload={upload}
      />
    );

    await userEvent.upload(screen.getByLabelText(/Published paper/), pdfFile('smith-2026.pdf'));

    await waitFor(() => expect(screen.getByText(/Attached: smith-2026\.pdf/)).toBeInTheDocument());
    expect(screen.getByText(/3\.0 MB/)).toBeInTheDocument();
  });

  it("reports the server's own refusal, and clears the answer with it", async () => {
    const onChange = jest.fn<void, [Answer]>();
    const upload = jest
      .fn<Promise<UploadedFileRef>, [File]>()
      // The server's sentence, verbatim — it is the only party that knows the
      // ceiling, and it already writes it for a person.
      .mockRejectedValue(new Error('That file is too large — the limit is 10 MB.'));

    render(
      <FormField
        field={fileFieldSpec()}
        // An earlier, successful upload is already the answer.
        value="tenants/1/form-uploads/7/oldreference.pdf"
        preferArabic={false}
        onChange={onChange}
        upload={upload}
      />
    );

    await userEvent.upload(screen.getByLabelText(/Published paper/), pdfFile('enormous.pdf'));

    await waitFor(() =>
      expect(screen.getByRole('alert')).toHaveTextContent('That file is too large — the limit is 10 MB.')
    );

    // CLEARED, not left pointing at the previous file. Submitting the old paper
    // while showing an error about the new one is the failure this asserts
    // against.
    expect(onChange).toHaveBeenCalledWith('');
    expect(onChange).not.toHaveBeenCalledWith('tenants/1/form-uploads/7/oldreference.pdf');
  });

  it('says files cannot be attached when the surface supplies no uploader', () => {
    render(
      <FormField
        field={fileFieldSpec()}
        value={undefined}
        preferArabic={false}
        onChange={jest.fn()}
      />
    );

    expect(screen.getByText(/Files cannot be attached here/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/Published paper/)).not.toBeInTheDocument();
  });
});
