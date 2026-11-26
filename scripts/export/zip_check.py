"""Verificador independiente del ZIP de la exportacion de datos (T-84): lo abre con `zipfile` (CRC incluido) y vuelca nombre, tamano y SHA-256.

Lo usan los tests de Pest como segundo lector, distinto del escritor PHP. Uso: python3 -I scripts/export/zip_check.py <fichero.zip>
"""
import hashlib
import json
import sys
import zipfile

with zipfile.ZipFile(sys.argv[1]) as archive:
    entries = archive.infolist()
    print(json.dumps({
        'bad': archive.testzip(),
        'zip64': any(entry.extract_version >= 45 for entry in entries),
        'versions': {entry.filename: entry.extract_version for entry in entries},
        'entries': {entry.filename: [entry.file_size, hashlib.sha256(archive.read(entry)).hexdigest()] for entry in entries},
    }))
