<?php

namespace PHPObjectSymbolResolver\ELF;

class Parser extends \PHPObjectSymbolResolver\Parser {
    const HEADER = "\x7fELF";

    public function parse(string $file): ObjectFile {
        $this->data = file_get_contents($file);
        if (strlen($this->data) < 16) {
            throw new \LogicException("File is too short to be an ELF file");
        }
        if (substr($this->data, 0, 4) !== self::HEADER) {
            throw new \LogicException("File is not in ELF format");
        }
        $this->obj = new ObjectFile;
        $this->parseElfHeader();
        $this->parseElfSections();
        $this->parseStringSections();
        $this->parseSymbolTables();

        $this->data = '';
        return $this->obj;
    }

    protected function parseElfHeader(): void {
        $this->obj->class = ord($this->data[4]);
        $this->obj->byteOrder = ord($this->data[5]);
        $this->obj->version = ord($this->data[6]);
        $this->obj->abi = ord($this->data[7]);
        $this->obj->abiVersion = ord($this->data[8]);

		if ($this->obj->byteOrder !== ObjectFile::BYTEORDER_LSB && $this->obj->byteOrder !== ObjectFile::BYTEORDER_MSB) {
			throw new \LogicException("Unknown byte ordering {$this->obj->byteOrder}");
		}

		$offset = 16;
        $this->obj->type = $this->parseHalf($offset);
        $this->obj->machine = $this->parseHalf($offset);
        $this->obj->eversion = $this->parseWord($offset);
        $this->obj->entry = $this->parseAddr($offset);
        $this->obj->phoff = $this->parseOff($offset);
        $this->obj->shoff = $this->parseOff($offset);
        $this->obj->flags = $this->parseWord($offset);
        $this->obj->ehsize = $this->parseHalf($offset);
        $this->obj->phentsize = $this->parseHalf($offset);
        $this->obj->phnum = $this->parseHalf($offset);
        $this->obj->shentsize = $this->parseHalf($offset);
        $this->obj->shnum = $this->parseHalf($offset);
        $this->obj->shstrndx = $this->parseHalf($offset);
    }

    protected function parseElfSections(): void {
        $offset = $this->obj->shoff;
        $shnum = $this->obj->shnum ?: $this->determineShnum();
        for ($i = 0; $i < $shnum; $i++) {
            $this->parseSectionHeader($this->obj->shoff + $i * $this->obj->shentsize);
        }
    }

    protected function parseStringSections(): void {
        $nameSection = $this->obj->sections[$this->obj->shstrndx];
        foreach ($this->obj->sections as $section) {
            $section->nameString = $this->readStringSectionOffset($nameSection, $section->name);
        }
    }

    protected function parseSymbolTables(): void {
        foreach ($this->obj->sections as $section) {
            if ($section->type !== Section::TYPE_SYMTAB && $section->type !== Section::TYPE_DYNSYM) {
                continue;
            }
            $this->parseSymbolTable($section);
        }
    }

    protected function parseSymbolTable(Section $section) {
        $offset = $section->offset;
        $size = $section->size;
        $end = $offset + $size;
        if ($section->type === Section::TYPE_SYMTAB) {
            $strtab = $this->findSection('.strtab');
        } else {
            $strtab = $this->findSection('.dynstr');
        }
        while ($offset < $end) {
            $symbol = $this->parseSymbol($offset);
            $symbol->nameString = $this->readStringSectionOffset($strtab, $symbol->name);
            $section->symbols[] = $symbol;
        }

    }

    protected function findSection(string $name): Section {
        foreach ($this->obj->sections as $section) {
            if ($section->nameString === $name) {
                return $section;
            }
        }
        throw new \LogicException("Could not find section $name");
    }

    protected function parseSymbol(int &$offset): Symbol {
        $symbol = new Symbol;
        if ($this->obj->class === ObjectFile::CLASS32) {
            $symbol->name = $this->parseWord($offset);
            $symbol->value = $this->parseAddr($offset);
            $symbol->size = $this->parseWord($offset);
            $symbol->info = $this->parseUChar($offset);
            $symbol->other = $this->parseUChar($offset);
            $symbol->shndx = $this->parseHalf($offset);
        } else {
            $symbol->name = $this->parseWord($offset);
            $symbol->info = $this->parseUChar($offset);
            $symbol->other = $this->parseUChar($offset);
            $symbol->shndx = $this->parseHalf($offset);
            $symbol->value = $this->parseAddr($offset);
            $symbol->size = $this->parseXWord($offset);
        }
        return $symbol;
    }

    protected function readStringSectionOffset(Section $section, int $offset): string {
        $mainOffset = $section->offset;
        $size = $section->size;
        $end = $mainOffset + $size;
        $buffer = '';
        for ($i = $offset + $mainOffset; $i < $end; $i++) {
            if ($this->data[$i] === "\0") {
                return $buffer;
            } else {
                $buffer .= $this->data[$i];
            }
        }
        return $buffer;
    }

    protected function parseSectionHeader(int $offset): void {
        $section = new Section;
        $section->name = $this->parseWord($offset);
        $section->type = $this->parseWord($offset);
        $section->flags = $this->parseXWord($offset);
        $section->addr = $this->parseAddr($offset);
        $section->offset = $this->parseOff($offset);
        $section->size = $this->parseXWord($offset);
        $section->link = $this->parseWord($offset);
        $section->info = $this->parseWord($offset);
        $section->addralign = $this->parseXWord($offset);
        $section->entsize = $this->parseXWord($offset);
        $this->obj->sections[] = $section;
    }
}
