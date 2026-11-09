import importlib.util
import pathlib
import tempfile
import unittest
import uuid
import zipfile
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('caption_archive', ROOT / 'scripts/studio/archive.py')
archive = importlib.util.module_from_spec(spec)
spec.loader.exec_module(archive)

class CaptionArchiveTest(unittest.TestCase):
    def test_real_archive_preserves_names_bytes_crc_and_zip64_headers(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'storage/framework/testing') as temporary:
            directory = pathlib.Path(temporary) / 'studio-exports/1/2' / str(uuid.uuid4())
            directory.mkdir(parents=True)
            contents = {'page-001.svg': '<svg>Hola 🎵</svg>'.encode(), 'page-002.png': bytes(range(256)) * 10000}
            for name, content in contents.items(): (directory / name).write_bytes(content)
            result = archive.archive(directory, list(contents))
            self.assertEqual(result['files'], 2)
            with zipfile.ZipFile(directory / 'pages.zip') as output:
                self.assertEqual(output.namelist(), list(contents))
                self.assertIsNone(output.testzip())
                for name, content in contents.items():
                    self.assertEqual(output.read(name), content)
                    self.assertGreaterEqual(output.getinfo(name).extract_version, 45)
            self.assertEqual(result['size'], (directory / 'pages.zip').stat().st_size)

    def test_archive_rejects_external_names_duplicate_inputs_and_existing_target(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'storage/framework/testing') as temporary:
            directory = pathlib.Path(temporary) / 'studio-exports/1/2' / str(uuid.uuid4()); directory.mkdir(parents=True)
            (directory / 'page-001.svg').write_bytes(b'<svg/>')
            for names in [['../outside'], ['/outside'], ['page-001.svg', 'page-001.svg']]:
                with self.assertRaises(archive.ArchiveError): archive.archive(directory, names)
                self.assertFalse((directory / 'pages.zip').exists())
            (directory / 'pages.zip').write_bytes(b'existing')
            with self.assertRaises(archive.ArchiveError): archive.archive(directory, ['page-001.svg'])
            self.assertEqual((directory / 'pages.zip').read_bytes(), b'existing')

    def test_failure_removes_own_partial_archive_and_keeps_sources(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'storage/framework/testing') as temporary:
            directory = pathlib.Path(temporary) / 'studio-exports/1/2' / str(uuid.uuid4()); directory.mkdir(parents=True)
            (directory / 'page-001.svg').write_bytes(b'<svg/>')
            with self.assertRaises(archive.ArchiveError): archive.archive(directory, ['page-001.svg', 'page-002.svg'])
            self.assertFalse((directory / 'pages.zip').exists())
            self.assertEqual((directory / 'page-001.svg').read_bytes(), b'<svg/>')

    def test_midstream_failure_and_disk_budget_remove_only_owned_output(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'storage/framework/testing') as temporary:
            directory = pathlib.Path(temporary) / 'studio-exports/1/2' / str(uuid.uuid4()); directory.mkdir(parents=True)
            (directory / 'page-001.svg').write_bytes(b'<svg/>')
            with mock.patch.object(archive, 'copy_bytes', side_effect=archive.ArchiveError('read failed')):
                with self.assertRaises(archive.ArchiveError): archive.archive(directory, ['page-001.svg'])
            self.assertFalse((directory / 'pages.zip').exists())
            with mock.patch.object(archive, 'MAX_ARCHIVE_BYTES', 64):
                with self.assertRaises(archive.ArchiveError): archive.archive(directory, ['page-001.svg'])
            self.assertFalse((directory / 'pages.zip').exists())
            self.assertEqual((directory / 'page-001.svg').read_bytes(), b'<svg/>')

    def test_page_budget_and_linked_sources_are_rejected_before_output(self):
        with tempfile.TemporaryDirectory(dir=ROOT / 'storage/framework/testing') as temporary:
            directory = pathlib.Path(temporary) / 'studio-exports/1/2' / str(uuid.uuid4()); directory.mkdir(parents=True)
            (directory / 'page-001.svg').write_bytes(b'<svg/>')
            with mock.patch.object(archive, 'MAX_PAGE_BYTES', 5):
                with self.assertRaises(archive.ArchiveError): archive.archive(directory, ['page-001.svg'])
            with mock.patch.object(pathlib.Path, 'is_symlink', return_value=True):
                with self.assertRaises(archive.ArchiveError): archive.archive(directory, ['page-001.svg'])
            self.assertFalse((directory / 'pages.zip').exists())

if __name__ == '__main__': unittest.main()
