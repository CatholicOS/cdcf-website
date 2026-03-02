'use client'

import { useEffect, useState } from 'react'

/**
 * Inline SVG path data for stylized fish in various poses.
 * Each fish is drawn in a 100×50 viewBox.
 */
const fishPaths = [
  // 1. Simple bass — facing right, forked tail
  `<path d="M15,25 C15,10 40,0 60,10 C70,14 75,20 78,25 C75,30 70,36 60,40 C40,50 15,40 15,25Z"/>
   <path d="M78,25 L95,10 L90,25 L95,40Z"/>
   <circle cx="30" cy="20" r="3"/>
   <path d="M40,10 Q50,18 40,25" fill="none" stroke="currentColor" stroke-width="1.5"/>`,

  // 2. Sleek fish — facing left, tapered tail
  `<path d="M85,25 C85,10 60,0 40,10 C30,14 25,20 22,25 C25,30 30,36 40,40 C60,50 85,40 85,25Z"/>
   <path d="M22,25 L5,12 L10,25 L5,38Z"/>
   <circle cx="70" cy="20" r="3"/>
   <path d="M55,10 Q48,18 55,25" fill="none" stroke="currentColor" stroke-width="1.5"/>`,

  // 3. Koi — rounded body, flowing split tail
  `<path d="M12,25 C12,12 35,4 55,12 C65,16 72,22 72,25 C72,28 65,34 55,38 C35,46 12,38 12,25Z"/>
   <path d="M72,25 Q82,14 96,8 Q86,25 96,42 Q82,36 72,25Z"/>
   <circle cx="28" cy="21" r="2.5"/>
   <path d="M35,10 Q45,6 55,12" fill="none" stroke="currentColor" stroke-width="1.5"/>
   <path d="M35,40 Q45,44 55,38" fill="none" stroke="currentColor" stroke-width="1.2"/>`,

  // 4. Ornate fish — facing right with scale lines, dorsal fin
  `<path d="M10,28 C10,14 30,4 55,12 C65,16 72,22 75,28 C72,34 65,40 55,44 C30,52 10,42 10,28Z"/>
   <path d="M75,28 L92,15 L88,28 L92,41Z"/>
   <circle cx="25" cy="24" r="2.5"/>
   <path d="M35,14 Q42,22 35,28 Q42,34 35,40" fill="none" stroke="currentColor" stroke-width="1"/>
   <path d="M48,11 Q55,20 48,28 Q55,36 48,44" fill="none" stroke="currentColor" stroke-width="1"/>
   <path d="M30,8 Q40,2 55,12" fill="none" stroke="currentColor" stroke-width="1.5"/>`,

  // 5. Koi — facing left with long flowing fins
  `<path d="M88,25 C88,12 65,4 45,12 C35,16 28,22 28,25 C28,28 35,34 45,38 C65,46 88,38 88,25Z"/>
   <path d="M28,25 Q18,14 4,8 Q14,25 4,42 Q18,36 28,25Z"/>
   <circle cx="72" cy="21" r="2.5"/>
   <path d="M65,10 Q55,6 45,12" fill="none" stroke="currentColor" stroke-width="1.5"/>
   <path d="M65,40 Q55,44 45,38" fill="none" stroke="currentColor" stroke-width="1.2"/>
   <path d="M72,8 Q78,2 85,6" fill="none" stroke="currentColor" stroke-width="1.3"/>`,
]

interface PlacedFish {
  pathIndex: number
  x: number
  y: number
  rotation: number
  size: number
  opacity: number
}

function randomBetween(min: number, max: number) {
  return min + Math.random() * (max - min)
}

export default function FishBackground({ count = 5 }: { count?: number }) {
  const [fish, setFish] = useState<PlacedFish[]>([])

  useEffect(() => {
    const placed: PlacedFish[] = []
    const minDistance = 8 // minimum % distance between fish centers

    for (let i = 0; i < count; i++) {
      let candidate: PlacedFish | null = null
      for (let attempt = 0; attempt < 50; attempt++) {
        const x = randomBetween(5, 90)
        const y = randomBetween(5, 90)
        const tooClose = placed.some((p) => {
          const dx = p.x - x
          const dy = p.y - y
          return Math.sqrt(dx * dx + dy * dy) < minDistance
        })
        if (!tooClose) {
          candidate = {
            pathIndex: Math.floor(Math.random() * fishPaths.length),
            x,
            y,
            rotation: randomBetween(-35, 35),
            size: randomBetween(80, 140),
            opacity: randomBetween(0.04, 0.09),
          }
          break
        }
      }
      if (candidate) placed.push(candidate)
    }

    setFish(placed)
  }, [count])

  if (fish.length === 0) return null

  return (
    <div
      aria-hidden="true"
      className="pointer-events-none absolute inset-0 overflow-hidden"
    >
      {fish.map((f, i) => (
        <svg
          key={i}
          viewBox="0 0 100 50"
          width={f.size}
          height={f.size / 2}
          style={{
            position: 'absolute',
            left: `${f.x}%`,
            top: `${f.y}%`,
            transform: `translate(-50%, -50%) rotate(${f.rotation}deg)`,
            opacity: f.opacity,
            color: '#B8860B',
            fill: 'currentColor',
          }}
          dangerouslySetInnerHTML={{ __html: fishPaths[f.pathIndex] }}
        />
      ))}
    </div>
  )
}
