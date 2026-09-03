# author_ident.h — stamp your name into the .so

Puts `created by: Z E R O ~ @Kingdarkpro` into the ELF `.so`, three ways at once
(all verified on a real build):

1. **`.comment`** — sits right next to the `Android clang version …` strings.
2. **ELF note** (`.note.zero`) — near the very start of the file, just after the
   ELF header / program headers (verified at file offset ~0x2ec).
3. **kept rodata string** — always shows in `strings file.so`.

## Use
Include it at the **top of your first source file** so it lands early:
```c
#include "author_ident.h"
```
Edit the text inside to your own signature. Nothing else to do — it's pure
markup, no runtime cost.

## Verify after building
```
readelf -p .comment libyourmod.so     # next to the clang ident
readelf -n libyourmod.so              # the ELF note, near the top
strings libyourmod.so | grep "created by"
```

## Honest note
This is a **visible signature**, not tamper-proof. A cracker can wipe it:
`llvm-strip -R .comment -R .note.zero libyourmod.so` and edit the rodata copy.
For attribution that survives stripping and names the leaker, use the forensic
watermark (`patches/watermark/`) as well — keep both: `author_ident.h` for the
public credit, `wm_tool` for the hidden, unforgeable trace.
