"""Compare DDL without MySQL's redundant explicit column charset serialization."""
import re

MATCHING_CHARSET=re.compile(r'\bCHARACTER SET ([A-Za-z0-9]+) COLLATE ([A-Za-z0-9_]+)\b')

def unquoted(text):
 def replace(match):
  charset,collation=match.groups()
  return 'COLLATE '+collation if collation.startswith(charset+'_') else match.group(0)
 return MATCHING_CHARSET.sub(replace,text)

def canonical_dump(body):
 """Retain all bytes except redundant matching charset tokens outside quotes.

 Only column DDL lines qualify. Collation itself, charset without a matching
 explicit collation, table defaults, column order/types/defaults, index definitions
 and quoted values/comments remain exact. This never changes the actual backup.
 """
 text=body.decode('utf-8');eligible={m.start()for m in re.finditer(r'(?m)^[ \t]+`',text)}
 def outside(value,offset):
  parts=[]
  for line in value.splitlines(keepends=True):
   start=text.rfind('\n',0,offset)+1
   parts.append(unquoted(line) if start in eligible else line);offset+=len(line)
  return ''.join(parts)
 chunks=[];start=0;i=0
 while i<len(text):
  if text[i] not in "'\"`":i+=1;continue
  chunks.append(outside(text[start:i],start));quote=text[i];start=i;i+=1
  while i<len(text):
   if text[i]=='\\':i+=2;continue
   if text[i]==quote:
    i+=1
    if i<len(text) and text[i]==quote:i+=1;continue
    break
   i+=1
  chunks.append(text[start:i]);start=i
 chunks.append(outside(text[start:],start))
 return ''.join(chunks).encode('utf-8')
