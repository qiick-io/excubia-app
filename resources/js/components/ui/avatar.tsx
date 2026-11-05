import * as AvatarPrimitive from "@radix-ui/react-avatar"
import * as React from "react"

import { cn } from "@/lib/utils"

/**
 * Per-user pastel swatches (`--avatar-1` … `--avatar-10` in the theme), as
 * literal classes so Tailwind generates them.
 */
const AVATAR_TONES = [
  "bg-avatar-1",
  "bg-avatar-2",
  "bg-avatar-3",
  "bg-avatar-4",
  "bg-avatar-5",
  "bg-avatar-6",
  "bg-avatar-7",
  "bg-avatar-8",
  "bg-avatar-9",
  "bg-avatar-10",
]

function seedFromId(id: number | string): number | null {
  if (typeof id === "number") {
    return Number.isNaN(id) ? null : id
  }

  const trimmed = id.trim()

  if (trimmed === "") {
    return null
  }

  const asNumber = Number(trimmed)

  if (Number.isFinite(asNumber)) {
    return asNumber
  }

  let hash = 0

  for (let i = 0; i < trimmed.length; i++) {
    hash = (Math.imul(31, hash) + trimmed.charCodeAt(i)) | 0
  }

  return hash
}

/**
 * Stable swatch class for a user (or group) id: numeric ids use abs(id) % 10,
 * other strings are hashed first. Undefined when there is no id.
 */
function avatarToneForId(id?: number | string | null): string | undefined {
  if (id === undefined || id === null) {
    return undefined
  }

  const seed = seedFromId(id)

  return seed === null
    ? AVATAR_TONES[0]
    : AVATAR_TONES[Math.abs(seed) % AVATAR_TONES.length]
}

const AvatarToneContext = React.createContext<string | undefined>(undefined)

function Avatar({
  className,
  userId,
  ...props
}: React.ComponentProps<typeof AvatarPrimitive.Root> & {
  /** Colours the fallback with the user's stable swatch. */
  userId?: number | string | null
}) {
  return (
    <AvatarToneContext.Provider value={avatarToneForId(userId)}>
      <AvatarPrimitive.Root
        data-slot="avatar"
        className={cn(
          "relative flex size-8 shrink-0 overflow-hidden rounded-full",
          className
        )}
        {...props}
      />
    </AvatarToneContext.Provider>
  )
}

function AvatarImage({
  className,
  ...props
}: React.ComponentProps<typeof AvatarPrimitive.Image>) {
  return (
    <AvatarPrimitive.Image
      data-slot="avatar-image"
      className={cn("aspect-square size-full", className)}
      {...props}
    />
  )
}

function AvatarFallback({
  className,
  ...props
}: React.ComponentProps<typeof AvatarPrimitive.Fallback>) {
  const tone = React.useContext(AvatarToneContext)

  return (
    <AvatarPrimitive.Fallback
      data-slot="avatar-fallback"
      className={cn(
        "flex size-full items-center justify-center rounded-full",
        tone ? `${tone} text-avatar-foreground` : "bg-muted",
        className
      )}
      {...props}
    />
  )
}

export { Avatar, AvatarImage, AvatarFallback, avatarToneForId }
