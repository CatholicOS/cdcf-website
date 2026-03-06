import { NextResponse } from 'next/server'
import { revalidateTag } from 'next/cache'
import { getStats } from '@/lib/stats'

export async function GET() {
  const stats = await getStats()
  return NextResponse.json(stats)
}

export async function POST() {
  revalidateTag('site-stats')
  return NextResponse.json({ revalidated: true })
}
