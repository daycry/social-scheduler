import importlib.util
from pathlib import Path
import struct
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("font_probe", ROOT / "scripts/studio/font_probe.py")
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)


class FontPreflightTests(unittest.TestCase):
    def test_woff_total_decoded_table_budget_cannot_hide_behind_header(self):
        data = bytearray(92)
        struct.pack_into(">4sIIHHI", data, 0, b"wOFF", 0x10000, len(data), 2, 0, 100)
        struct.pack_into(">4sIIII", data, 44, b"head", 84, 4, 17 * 1024 * 1024, 0)
        struct.pack_into(">4sIIII", data, 64, b"name", 88, 4, 17 * 1024 * 1024, 0)
        with self.assertRaises(ValueError):
            probe.preflight(bytes(data), 32 * 1024 * 1024)

    def test_real_licensed_ttf_and_woff2_pass_preflight(self):
        for ext in ("ttf", "woff2"):
            probe.preflight((ROOT / f"tests/Fixtures/fonts/SpaceGrotesk-Variable.{ext}").read_bytes(), 32 * 1024 * 1024)


if __name__ == "__main__":
    unittest.main()
