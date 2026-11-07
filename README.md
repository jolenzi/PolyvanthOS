<p align="center">
  <img src="docs/images/logo.svg" width="128" height="128" alt="PolyvanthOS logo">
</p>

# PolyvanthOS

A 64-bit multitasking hobby OS with native, MS-DOS, and Linux personalities.

The kernel is written in PHP. A small compiler, `tools/polyc`, turns it into C, and clang
builds that for bare metal.

**Fun fact:** the kernel is written in a subset of PHP, just for fun. PHP was never meant for bare
metal, so `polyc` reads ordinary PHP files, checks the types, and writes C that clang can compile.
The sources are still real PHP: an editor understands them and `php -l` accepts them.

## The name

PolyvanthOS comes from two Greek words: *poly* (πολύς, "many") and *anthos* (ἄνθος, "flower" or
"blossom"). Together they mean "many-flowered", the same root as the polyanthus flower.

It describes the design: one kernel, many blooms. The native, MS-DOS, and Linux personalities grow
from the same core, and more can be added. The name also ends in **OS**, so *anthos* doubles as
"operating system".

The logo follows the same idea: six petals around a single core.

## Build

    sudo apt install clang lld make git php-cli xorriso qemu-system-x86
    git clone --recurse-submodules <repo-url>
    cd polyvanthos
    make -C limine
    make run

Output appears on the serial console. Press Ctrl+A then X to quit QEMU.

## Documentation

How the code works, and why it was written that way, is in [docs/](docs/README.md). The
language the kernel is written in is described in [docs/language.md](docs/language.md).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and the [Code of Conduct](CODE_OF_CONDUCT.md). To report a
security issue, see [SECURITY.md](SECURITY.md).

## Authors

See [AUTHORS](AUTHORS).

## License

GPL-2.0-only. See LICENSE. Limine is BSD-2-Clause and keeps its own license.

MS-DOS is a trademark of Microsoft Corporation. Linux is a registered trademark of Linus
Torvalds. PolyvanthOS is not affiliated with or endorsed by either.

## AI assistance

Parts of this project were developed with help from Claude AI and GitHub Copilot.
