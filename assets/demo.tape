# Records a live Tapper session into assets/demo.gif (converted to
# assets/demo.webp by scripts/record-demo.sh, which is the intended
# entrypoint — it also handles cleanup and the gif->webp conversion).
#
# Every command below is relative to the project root, so don't run
# `vhs assets/demo.tape` directly unless your shell is already cd'd there.

Output assets/demo.gif

Require php

Set Shell "bash"
Set FontSize 18
Set Width 1200
Set Height 700
Set Padding 24
Set WindowBar Colorful
Set Theme "Dracula"
Set TypingSpeed 40ms

# Launch the log producer in the background, detached, before Tapper itself
# is on screen — examples/BasicExample.php has no output of its own (every
# line it prints goes over the socket to the TUI), so there is nothing to
# hide visually, only the launcher command itself.
Hide
Type "rm -f tapper.sock tapper.log" Enter
Type "nohup bash -c 'sleep 2 && php examples/BasicExample.php' > /dev/null 2>&1 & disown; clear" Enter
Show

Type "php bin/tapper"
Enter

# examples/BasicExample.php streams its scripted tp() calls into view over
# the next ~7s, then blocks three times in a row on tp(...)->wait() — each
# wait() only resolves on an Enter keypress inside the Tapper window itself,
# so we send it here to keep the recording unattended.
Sleep 9s
Enter
Sleep 2s
Enter
Sleep 2s
Enter
Sleep 3s

# Details view: move the cursor up onto the large structured-data payload
# and open it (Enter/Space) to show the syntax-highlighted JSON, scroll down
# and to the right within it (Down/j and Right/l, see Details::down() and
# Details::scrollRight()), then close it again (Backspace/Esc).
Up 7
Sleep 500ms
Enter
Sleep 1s
Down 6
Sleep 600ms
Right 8
Sleep 2s
Backspace
Sleep 500ms

# Filtering: `/` opens the filter line (Console/Components/Filter.php),
# typing narrows the list live, Enter confirms it, and Esc clears the
# filter and jumps back to the live tail.
Type "/"
Sleep 300ms
Type "wait"
Sleep 800ms
Enter
Sleep 2500ms
Escape
Sleep 1500ms
