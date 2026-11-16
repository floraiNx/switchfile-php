# Changelog

## 0.3.0

- `convertWithoutStorage()` returns `notes` and `incomplete`, read from the headers `X-Notes` and `X-Incomplete`. The
  response is only the file, so the notes on the result, such as the pages that could not be converted, were not
  available before.

## 0.2.0

Every endpoint of the switchfile API is now available in the SDK.

- `batches()`: `create()`, `get()`, `wait()`, `delete()` for up to 50 conversions with one request, optionally with all
  results as one ZIP file.
- `archives()->create()`: a ZIP file of finished conversions. Load it with `download($archive)`.
- `captures()`: `create()`, `get()`, `wait()`, `delete()`, `findByIdempotencyKey()` to save a web page as PDF, PNG or
  JPG.
- `accessibilityChecks()`: the same methods to check a PDF against the machine-checkable criteria of PDF/UA.
- `uploads()`: `create()` and `delete()`. Send a file once and use its `upload_id` several times.
- `webhooks()`: `create()`, `list()`, `delete()` for the account webhook, plus `verify()` and `constructEvent()`.
  `webhookHandler()` stays.
- `conversions()->findByIdempotencyKey()`: find what a request with an `Idempotency-Key` created.
- `estimate()`: credits and price of a conversion without creating it. Only the file name and
  the first byte of the file are sent, so the estimate does not check the upload size limit.
- `download()` accepts a finished website capture and loads its result file.
- `convertFormats()`: every supported pair of formats, with options on request.
- `health()`: status of the API.
- `SwitchfileException::getIndex()`: the entry of a batch or archive that caused the error.

## 0.1.0

- First version.
