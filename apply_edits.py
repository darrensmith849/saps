import os

file_path = 'ngd-renewals-dashboard.php'
with open(file_path, 'r') as f:
    lines = f.readlines()

# Read replacements
with open('temp_render_ops_content.php', 'r') as f:
    render_ops = f.read()
with open('temp_compute_author_content.php', 'r') as f:
    compute_auth = f.read()

# Lines are 0-indexed.
# compute_author_state: 3012 - 3157 (inclusive 1-based)
# Python slice [3011 : 3157] covers indices 3011 to 3156.
start_compute = 3012 - 1
end_compute = 3157

# render_ops_page: 2573 - 2978 (inclusive 1-based)
# Python slice [2572 : 2978] covers indices 2572 to 2977.
start_render = 2573 - 1
end_render = 2978

# Correct check for newline
if not compute_auth.endswith('\n'):
    compute_auth += '\n'
if not render_ops.endswith('\n'):
    render_ops += '\n'

# Process from bottom up to avoid index shifting
# 1. Compute Author State (Lines 3012-3157)
print(f"Replacing compute_author_state at lines {start_compute+1}-{end_compute}")
lines[start_compute:end_compute] = [compute_auth]

# 2. Render Ops Page (Lines 2573-2978)
print(f"Replacing render_ops_page at lines {start_render+1}-{end_render}")
lines[start_render:end_render] = [render_ops]

with open(file_path, 'w') as f:
    f.writelines(lines)

print("Applied edits successfully.")
