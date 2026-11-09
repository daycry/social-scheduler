"""ZIP64 fallback for generated private Studio pages. No consumer paths or archive input."""
import json
import os
import pathlib
import re
import stat
import sys
import zipfile

MAX_PAGE_BYTES = 64_000_000
MAX_ARCHIVE_BYTES = 8 * 1024 ** 3
BLOCK = 1024 * 1024

class ArchiveError(ValueError):
    pass

def same_path(left, right):
    return os.path.normcase(str(left)) == os.path.normcase(str(right))

def copy_bytes(source, output, size, target):
    copied = 0
    while True:
        block = source.read(BLOCK)
        if not block:
            break
        copied += len(block)
        if copied > size:
            raise ArchiveError('La página cambió de tamaño.')
        output.write(block)
        if target.tell() > MAX_ARCHIVE_BYTES:
            raise ArchiveError('El archivo supera su presupuesto de disco.')
    if copied != size:
        raise ArchiveError('La página está incompleta.')

def archive(directory, names):
    directory = pathlib.Path(directory)
    if not directory.is_absolute() or not re.search(r'[/\\]studio-exports[/\\]\d+[/\\]\d+[/\\][a-fA-F0-9-]{36}$', str(directory)):
        raise ArchiveError('El directorio no es un intento privado generado.')
    if not same_path(directory.resolve(strict=True), directory) or not directory.is_dir():
        raise ArchiveError('El directorio no admite enlaces externos.')
    if not isinstance(names, list) or not 1 <= len(names) <= 100 or len(set(names)) != len(names):
        raise ArchiveError('Indica hasta100páginas únicas.')
    sources = []
    total = 0
    for name in names:
        if not isinstance(name, str) or not re.fullmatch(r'page-\d{3}\.(?:png|jpg|webp|svg|pdf)', name):
            raise ArchiveError('Nombre de página no admitido.')
        source = directory / name
        try:
            if source.is_symlink() or source.is_junction() or not same_path(source.resolve(strict=True), source):
                raise ArchiveError('La página no admite enlaces externos.')
            info = source.stat(follow_symlinks=False)
        except OSError as error:
            raise ArchiveError('Falta una página generada.') from error
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or not 1 <= info.st_size <= MAX_PAGE_BYTES:
            raise ArchiveError('La página no es un archivo regular dentro de su límite.')
        total += info.st_size
        sources.append((name, source, info))
    if total > MAX_ARCHIVE_BYTES:
        raise ArchiveError('Las páginas superan el presupuesto de disco.')
    destination = directory / 'pages.zip'
    owned = None
    try:
        # EXCL guarantees a retry can never overwrite another completed archive.
        with destination.open('xb') as target:
            owned = os.fstat(target.fileno())
            with zipfile.ZipFile(target, mode='w', compression=zipfile.ZIP_STORED, allowZip64=True) as output:
                for name, source, expected in sources:
                    flags = os.O_RDONLY | getattr(os, 'O_BINARY', 0) | getattr(os, 'O_NOFOLLOW', 0)
                    with os.fdopen(os.open(source, flags), 'rb') as stream:
                        opened = os.fstat(stream.fileno())
                        if not same_path(source.resolve(strict=True), source) or (opened.st_dev, opened.st_ino, opened.st_size, opened.st_mtime_ns) != (expected.st_dev, expected.st_ino, expected.st_size, expected.st_mtime_ns):
                            raise ArchiveError('La página cambió antes de empaquetarla.')
                        entry = zipfile.ZipInfo(name)
                        entry.compress_type = zipfile.ZIP_STORED
                        entry.external_attr = 0o600 << 16
                        # Force64 now, so streaming never assumes a four-gigabyte cap.
                        with output.open(entry, 'w', force_zip64=True) as member:
                            copy_bytes(stream, member, opened.st_size, target)
            target.flush()
            os.fsync(target.fileno())
            size = target.tell()
            if size > MAX_ARCHIVE_BYTES:
                raise ArchiveError('El archivo supera el presupuesto de disco.')
        return {'files': len(sources), 'size': size, 'source_bytes': total}
    except Exception as error:
        if owned is not None:
            try:
                current = destination.stat(follow_symlinks=False)
                if (current.st_dev, current.st_ino) == (owned.st_dev, owned.st_ino):
                    destination.unlink()
            except OSError:
                pass
        raise ArchiveError('No se pudo crear el archivo ZIP privado.') from error

def main():
    try:
        if len(sys.argv) < 3:
            raise ArchiveError('Faltan páginas.')
        result = archive(pathlib.Path(sys.argv[1]), sys.argv[2:])
        print(json.dumps({'ok': True, **result}))
        return 0
    except (ArchiveError, OSError, ValueError):
        print(json.dumps({'ok': False, 'error_code': 'private_archive_failed'}))
        return 1

if __name__ == '__main__':
    raise SystemExit(main())
