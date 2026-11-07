# Contributing to PolyvanthOS

Thanks for your interest. PolyvanthOS is a small hobby project, so please open an issue to discuss
larger changes before you start on them.

## Licensing

PolyvanthOS is licensed under GPL-2.0-only. By contributing, you agree that your contribution is
licensed under the same terms.

- Every new file needs the same two SPDX header lines as the existing files (copy them from any
  file in `src/`). `make check` fails if they are missing.
- Only submit code you wrote yourself or have the right to submit.
- Code from other projects must be under a license compatible with GPL-2.0-only. GPL-3.0-only code
  cannot be accepted. Name the source and its license in the commit message, and add it to
  `NOTICE` if it needs attribution.

## Sign your commits (DCO)

Every commit must include a `Signed-off-by` line, which certifies the
[Developer Certificate of Origin](https://developercertificate.org/): that you have the right to
submit the code under the project's license. Add it with:

    git commit -s

## AI-assisted contributions

AI tools are allowed. If an AI tool wrote a meaningful part of your change, say so in the pull
request. You are responsible for the change as if you wrote it yourself: you must understand it,
have tested it, and have checked that it is not copied from code under an incompatible license.

## Before you open a pull request

    make check    # formatting, PHP syntax and licensing checks
    make test-compiler    # the compiler's own tests
    make test     # boots the kernel in QEMU and checks the selftest output

- Follow the style of the PHP around your change. Format the C in `native/` with `clang-format`
  using the project's `.clang-format`.
- Keep architecture-specific code under `src/arch/`.
- Write commit messages in the imperative mood, for example "Add page frame allocator".
- Keep each commit to one logical change.

## Code of conduct

This project follows the [Code of Conduct](CODE_OF_CONDUCT.md).
