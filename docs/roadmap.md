# Roadmap

What is done, and what comes next. The order is a plan, not a promise. Some steps will move
around once we run into things we didn't expect.

The goal is a 64-bit multitasking OS with native, MS-DOS, and Linux personalities. Everything
below is a step toward that. Steps get ticked off when they are merged, with a doc page where
there is one.

**Next up:** paging (the first item in section A).

## Done

- [x] **Boot with Limine.** The kernel loads, checks the protocol revision, and starts.
  See [boot/limine.md](boot/limine.md).
- [x] **Serial output.** Text from the kernel to the host over COM1.
  See [drivers/serial.md](drivers/serial.md).
- [x] **`kprintf`.** Formatted printing, with a selftest. See [lib/kprintf.md](lib/kprintf.md).
- [x] **Panic and halt.** The kernel stops with a message when something is badly wrong.
  See [lib/panic.md](lib/panic.md).
- [x] **GDT.** Our own segment table instead of Limine's. See [arch/x86_64/gdt.md](arch/x86_64/gdt.md).
- [x] **IDT and exception handlers.** All 32 CPU exceptions print a register dump and stop.
  See [arch/x86_64/idt.md](arch/x86_64/idt.md).
- [x] **TSS and a double fault stack.** A broken stack gives a crash report instead of a silent
  reboot. See [arch/x86_64/gdt.md](arch/x86_64/gdt.md).
- [x] **Memory map and HHDM.** Read what RAM there is and where all of it is mapped.
  See [mm/memmap.md](mm/memmap.md).
- [x] **Physical page allocator.** A bitmap that hands out 4 KiB pages, and runs of pages.
  See [mm/pmm.md](mm/pmm.md).
- [x] **Kernel heap.** `kmalloc` and `kfree` for any size. See [mm/heap.md](mm/heap.md).
- [x] **Project basics.** Licensing checks (REUSE), formatting checks, CI, and boot selftests.

## A. Finish the memory foundation

- [ ] **Own page tables.** Build the 4-level tables ourselves, map the kernel and the HHDM, and
  switch away from Limine's. *Medium.*
- [ ] **A mapping API.** `Paging::map` and `Paging::unmap` with permission flags, including
  no-execute for data. *Medium.*
- [ ] **Guard pages, and IST stacks for NMI and machine check.** An unmapped page below each
  stack, so an overflow causes a fault instead of silent damage. *Easy once the two above are done.*
- [ ] **A better page-fault report.** Decode the error code and print a backtrace by walking the
  stack frames. *Easy.*

## B. Interrupts and time

- [ ] **Framebuffer console.** Text on screen as well as on serial. Optional, but nice.
  *Medium.*
- [ ] **ACPI tables.** Find the interrupt controller details in the MADT table. *Medium.*
- [ ] **Local APIC.** Turn off the old PIC, handle hardware interrupts from vector 32 up, and
  acknowledge them. *Medium.*
- [ ] **Timer tick.** Use the APIC timer, calibrated against another clock, and add `sleep`.
  *Medium.*
- [ ] **PS/2 keyboard.** An interrupt handler, so the kernel can read keys. *Easy.*

## C. Multitasking

- [ ] **Kernel threads.** A task structure, and a context switch in assembly that saves
  registers and swaps stacks. *Hard the first time.*
- [ ] **A scheduler.** Round-robin on the timer tick, with `yield`, `sleep`, and block and wake.
  *Medium.*
- [ ] **Locks.** Spinlocks, an "interrupts off" guard, mutexes, and wait queues. *Medium, but
  easy to get subtly wrong.*
- [ ] **Heap upgrades.** Let it grow, add a zeroing allocator, make `delete` work,
  and make it safe with several threads. *Easy to medium.*

## D. User mode

- [ ] **Ring 3.** User segments in the GDT, `syscall` and `sysret`, a page table per process,
  and safe functions to copy data to and from user memory. *Hard.*
- [ ] **ELF loader.** Load a static program and run the first user program. *Medium.*
- [ ] **Processes.** A process table, starting programs, exit and wait. *Medium.*

## E. Storage

- [ ] **Ramdisk and a VFS.** An in-memory filesystem and the layer every filesystem plugs into.
  *Medium.*
- [ ] **PCI and a disk driver.** Start with virtio-blk, which QEMU supports, then read FAT32 or
  ext2. *Hard.*

## F. The personalities

- [ ] **A native syscall interface and a small runtime library** for user programs, so there is
  something to write real programs against. *Medium.*
- [ ] **Linux personality.** Implement Linux's system call numbers (`write`, `mmap`, `brk`,
  `clone`, and so on) and run a static busybox. *Hard, but the target is well defined.*
- [ ] **MS-DOS personality.** 64-bit mode has no virtual-8086 mode, so DOS programs can't just
  run on the CPU. The likely answer is to emulate a 16-bit x86 CPU in user space and implement
  DOS's `INT 21h` calls. This is the biggest open design question.

## Later

- [ ] Starting the other CPUs (SMP), using Limine's multiprocessor request.
- [ ] Networking: virtio-net and a TCP/IP stack.
- [ ] An ARM port. The code under `src/arch/` is kept separate for this. Limine needs base
  revision 6 or higher on ARM, and the CPU-specific pieces (page tables, interrupts, context
  switch) would be rewritten.
