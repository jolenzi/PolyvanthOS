# Building and testing

## What you need

On Debian or Ubuntu:

```
sudo apt install clang lld make git php-cli xorriso qemu-system-x86
git clone --recurse-submodules <repo-url>
cd polyvanthos
make -C limine
```

`--recurse-submodules` matters, because Limine is a git submodule. If you forgot it, run
`git submodule update --init`. `make -C limine` builds the `limine` helper tool, which we need to
make the ISO bootable.

## Make targets

| Command | What it does |
|---|---|
| `make` | Builds the kernel and the ISO (`polyvanthos.iso`). |
| `make run` | Builds, then boots the ISO in QEMU. Output shows in your terminal. Press Ctrl+A then X to quit. |
| `make check` | Checks formatting of `native/` with `clang-format`, PHP syntax with `php -l`, and licensing with `reuse lint`. |
| `make test-compiler` | Runs the polyc tests: compiles test programs and runs them on this computer, and checks that bad programs are rejected. |
| `make test` | Boots the ISO in QEMU for up to 15 seconds and checks the serial log. |
| `make clean` | Removes `build/`, `kernel`, `iso/` and the ISO file. |

## How the build works

There are two steps before the usual C build. First `tools/polyc/polyc` compiles all the PHP
in `src/` in one run. It writes `build/gen/polyc.h` and one C file per PHP file under
`build/gen/`. It leaves a file alone when its contents did not change, so only real changes are
rebuilt. Then each generated C file is compiled to an object file under `build/`, and the `.S`
assembly file goes through the same compiler. Finally `ld.lld` links everything into one file called
`kernel`, using `src/arch/x86_64/linker.ld` to decide where each section goes.

The C compiler needs `-Inative -Ibuild/gen`: `native/` holds the hand-written headers that the
`#[Native]` classes refer to, and `build/gen/` holds the generated one.

The ISO step copies the kernel, `limine.conf` and Limine's boot files into an `iso/` folder, and
`xorriso` turns that folder into an ISO that boots on both BIOS and UEFI. The last step,
`limine bios-install`, writes the BIOS boot code into the image.

## The compiler flags, and why

We are compiling the generated C, which runs with no operating system underneath it, so the usual assumptions
are off. Each flag turns one of them off or fixes one problem.

| Flag | Why |
|---|---|
| `--target=x86_64-unknown-none-elf` | Build for bare metal, not for Linux. No system headers or libraries get pulled in. |
| `-std=gnu11` | The language version. polyc's output needs compound literals, `_Static_assert` and GNU attributes, which this has. |
| `-ffreestanding` | Don't assume a C library exists. |
| `-fno-stack-protector` | Stack canaries need a runtime helper and a value to check against. |
| `-fno-pic` | The kernel sits at a fixed address, so position-independent code would only slow it down. |
| `-mno-red-zone` | The x86_64 ABI lets a small function use 128 bytes below the stack pointer without moving it. An interrupt pushes its frame right there and would overwrite that data. Kernels turn the red zone off. |
| `-mcmodel=kernel` | The kernel is linked in the top 2 GB of the address space, and this tells the compiler it can use short 32-bit addresses that sign-extend into that range. |
| `-mno-sse`, `-mno-mmx` | Don't use vector or floating point registers. The kernel doesn't save them on an interrupt, and SSE has to be switched on before use. |
| `-O2 -g -Wall -Wextra` | Optimise, keep debug info (needed for `addr2line`), and warn about likely mistakes. |

The link step uses `-z max-page-size=0x1000` so segments are padded to 4 KiB pages, not the
default of 2 MiB. That keeps the file small and matches the page size we'll use later.

## The checks

`make check` runs three things.

`clang-format --dry-run --Werror` reads `.clang-format` and fails if any header in `native/` would
be changed by the formatter. To fix a file, run `clang-format -i <file>`. (The PHP is not
formatted by a tool. Follow the style of the files around it.)

`php -l` checks that every PHP file in `src/` and `tools/` is valid PHP. That matters because polyc
accepts a subset of PHP: the sources are still real PHP files that an editor understands.

`reuse lint` checks that every file has a copyright and a license. Source files carry two header
lines, and files that can't hold comments (like images) are covered by `REUSE.toml`.

## The compiler tests

`make test-compiler` runs `php tools/polyc/tests/run.php`. Every file in `tools/polyc/tests/cases/`
that starts with `// expect:` lines is compiled by polyc, built with the computer's own C compiler,
and run, and the output has to match. Every file in `tools/polyc/tests/errors/` has to be
rejected with the message named in its `// error:` line. See [the compiler](tools/polyc.md).

## The boot test

`make test` boots the ISO with the serial port connected to a file (`build/serial.log`) and no
display. After 15 seconds it stops QEMU and looks at the file. It passes if the log contains
`--- end selftest ---` and does not contain `PANIC`.

It is a crude test, but it catches most real breakage: a kernel that won't boot, a crash, a
panic, or a `kprintf` that stops printing.

## GitHub CI

`.github/workflows/ci.yml` runs the same steps on every push: build Limine, `make check`,
`make test-compiler`, `make`, `make test`. It pins the same `clang-format` version we use locally, because different versions
format code slightly differently.

## Debugging a crash

When the kernel hits a CPU exception, it prints a crash report that includes `RIP`, the address of
the instruction that failed. The kernel is built with `-g`, so you can turn that into a source
line:

```
addr2line -e kernel 0xffffffff8000002e
```

That prints a line in the generated C, like `build/gen/arch/x86_64/IDT.c:37`. Each generated file
is named after the PHP file it came from, and the functions keep the names of the PHP methods
(`Mm_PMM_allocPage`), so it is easy to find the matching PHP.

If the machine just resets with no message, ask QEMU to log what the CPU is doing:

```
qemu-system-x86_64 -cdrom polyvanthos.iso -serial stdio -display none \
    -no-reboot -d int,cpu_reset -D qemu.log
```

`-no-reboot` stops the reset loop, and `qemu.log` then lists every exception, including the ones
that ended in a triple fault.
