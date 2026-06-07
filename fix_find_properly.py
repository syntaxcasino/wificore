#!/usr/bin/env python3
import re
import sys

def fix_find_expressions(content):
    """
    Fix [find ...] expressions in :do{} blocks for RouterOS 7.x binary API compatibility.
    
    Rules:
    1. /xxx remove [/xxx find name="..."] → /xxx remove "..."
    2. /xxx set [/xxx find name="..."] → /xxx set "..."
    3. /xxx remove [/xxx find <other>=...] → /xxx remove [find <other>=...]
    4. /xxx set [/xxx find <other>=...] → /xxx set [find <other>=...]
    """
    
    # Pattern 1: remove [/path find name="value"] → remove "value"
    content = re.sub(
        r'(/[a-z/]+ remove) \[/[a-z/ ]+ find name=(\\"[^"]+\\")\]',
        r'\1 \2',
        content
    )
    
    # Pattern 2: set [/path find name="value"] → set "value"
    content = re.sub(
        r'(/[a-z/]+ set) \[/[a-z/ ]+ find name=(\\"[^"]+\\")\]',
        r'\1 \2',
        content
    )
    
    # Pattern 3: remove [/path find <non-name>=...] → remove [find <non-name>=...]
    content = re.sub(
        r'(/[a-z/]+ remove) \[/[a-z/ ]+ find ([a-z~-]+[^]]+)\]',
        r'\1 [find \2]',
        content
    )
    
    # Pattern 4: set [/path find <non-name>=...] → set [find <non-name>=...]
    content = re.sub(
        r'(/[a-z/]+ set) \[/[a-z/ ]+ find ([a-z~-]+[^]]+)\]',
        r'\1 [find \2]',
        content
    )
    
    return content

if __name__ == '__main__':
    for filepath in sys.argv[1:]:
        with open(filepath, 'r') as f:
            content = f.read()
        
        fixed_content = fix_find_expressions(content)
        
        with open(filepath, 'w') as f:
            f.write(fixed_content)
        
        print(f"Fixed {filepath}")
