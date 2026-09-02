// ============================================================================
//  wm_slots.h — reserved forensic-watermark slots for the client build.
//
//  Include this once in your app and reference WM_SLOTS_DATA somewhere (so the
//  linker keeps it). Ship the MASTER build with these slots as-is; the download
//  server stamps a per-buyer opaque token into them at download time
//  (wm_tool embed). The app itself NEVER reads them — they are passive marks.
//
//  Each buyer therefore receives a binary that carries a hidden, encrypted,
//  unforgeable code identifying THEM. If a free copy leaks, wm_tool extract
//  reads that code and names the buyer — even from an offline, re-branded copy.
// ============================================================================
#ifndef WM_SLOTS_H
#define WM_SLOTS_H

// 16-byte locator: only the MASTER build contains it. The embedder finds it and
// overwrites the whole 32-byte slot with the opaque token, so buyers' binaries
// no longer contain this pattern.
#define WM_LOCATOR { 0xA7,0x11,'W','M','S','L','O','T',0x00,0x7F,'z','e','r','o',0x11,0xA7 }
#define WM_SLOT_COUNT 3

// Three redundant 32-byte slots. Redundancy: a leaker who zeroes one still
// leaves the others. Keep them in their own section for tidy placement.
__attribute__((used, section("wmslots")))
volatile unsigned char WM_SLOTS_DATA[WM_SLOT_COUNT][32] = {
    { 0xA7,0x11,'W','M','S','L','O','T',0x00,0x7F,'z','e','r','o',0x11,0xA7, 0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0 },
    { 0xA7,0x11,'W','M','S','L','O','T',0x00,0x7F,'z','e','r','o',0x11,0xA7, 0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0 },
    { 0xA7,0x11,'W','M','S','L','O','T',0x00,0x7F,'z','e','r','o',0x11,0xA7, 0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0 },
};

#endif
